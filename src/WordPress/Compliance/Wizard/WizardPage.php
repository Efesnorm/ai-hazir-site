<?php
/**
 * Tools → AI Uyum Sihirbazı.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Compliance\Wizard;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\Wizard\FixStep;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Compliance\Admin\CompliancePage;
use AIHazirSite\WordPress\Compliance\ComplianceModule;

/**
 * Every step is applied only on the admin's click (nonce + manage_options) and can be undone
 * with one click. "Bitir ve yeniden tara" scans again and shows the score before and after.
 */
final class WizardPage {

	public const SLUG       = 'aihs-wizard';
	public const CAPABILITY = 'manage_options';
	public const APPLY      = 'aihs_wizard_apply';
	public const UNDO       = 'aihs_wizard_undo';
	public const FINISH     = 'aihs_wizard_finish';
	public const DISMISS    = 'aihs_wizard_dismiss';

	/**
	 * Registers the page, handlers and the suggestion.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_notices', array( self::class, 'suggestion' ) );
		add_action( 'admin_post_' . self::APPLY, array( $this, 'on_apply' ) );
		add_action( 'admin_post_' . self::UNDO, array( $this, 'on_undo' ) );
		add_action( 'admin_post_' . self::FINISH, array( $this, 'on_finish' ) );
		add_action( 'admin_post_' . self::DISMISS, array( $this, 'on_dismiss' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public function add_page(): void {
		AdminMenu::add( __( 'AI Uyum Sihirbazı', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return AdminMenu::url( self::SLUG, $args );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		echo '<div class="wrap">' . self::render_html( FormState::take() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state Carried-over state.
	 */
	public static function render_html( array $state ): string {
		$journal  = WizardModule::journal();
		$plan     = WizardModule::plan();
		$baseline = $journal->baseline();
		$latest   = ComplianceModule::store()->latest();

		$html = '<h1>' . esc_html__( 'AI Uyum Sihirbazı', 'ai-hazir-site' ) . '</h1>'
			. '<p>' . esc_html__( 'Taramadaki eksikleri adım adım tamamlar. Sihirbaz yalnızca AI Hazır Site\'nin kendi ayarlarını ve verisini değiştirir; tema, başka eklenti, WordPress ayarı ve sunucu dosyalarına dokunmaz. Her adım onayınızla uygulanır ve tek tıkla geri alınabilir.', 'ai-hazir-site' ) . '</p>';

		if ( array() !== $state['errors'] ) {
			$html .= '<div class="notice notice-error inline" id="aihs-wizard-errors"><p>' . esc_html__( 'Uygulanamadı:', 'ai-hazir-site' ) . '</p><ul>'
				. implode( '', array_map( static fn( string $e ): string => '<li>' . esc_html( $e ) . '</li>', $state['errors'] ) ) . '</ul></div>';
		}

		if ( null !== $baseline && null !== $latest ) {
			$html .= self::comparison( $baseline, $latest );
		}

		$html .= '<h2>' . esc_html__( 'Önerilen adımlar', 'ai-hazir-site' ) . '</h2>';
		if ( array() === $plan['actions'] ) {
			$html .= '<p id="aihs-wizard-no-actions">' . esc_html__( 'Sihirbazın uygulayabileceği bir adım kalmadı.', 'ai-hazir-site' ) . '</p>';
		}
		foreach ( $plan['actions'] as $step ) {
			$html .= self::action( $step, $state );
		}

		$applied = $journal->applied();
		if ( array() !== $applied ) {
			$wizard = WizardModule::wizard();
			$html  .= '<h2>' . esc_html__( 'Uygulanan adımlar', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-wizard-applied"><tbody>';
			foreach ( array_keys( $applied ) as $step ) {
				$blocker = $wizard->undo_blocker( $step );
				$html   .= '<tr><td>' . esc_html( self::step_names()[ $step ] ?? $step ) . '</td><td>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. '<input type="hidden" name="action" value="' . esc_attr( self::UNDO ) . '"><input type="hidden" name="step" value="' . esc_attr( $step ) . '">'
					. wp_nonce_field( self::UNDO . '_' . $step, '_wpnonce', true, false )
					. get_submit_button( __( 'Geri al', 'ai-hazir-site' ), 'secondary small', 'submit', false, null === $blocker ? array() : array( 'disabled' => 'disabled' ) )
					. ( null === $blocker ? '' : ' <span class="description">' . esc_html( $blocker ) . '</span>' )
					. '</form></td></tr>';
			}
			$html .= '</tbody></table>';
		}

		if ( array() !== $plan['manual'] ) {
			$html .= '<h2>' . esc_html__( 'Elle yapılacaklar', 'ai-hazir-site' ) . '</h2><p>' . esc_html__( 'Bunlar eklentinin dışında kalır; sihirbaz değiştirmez, nasıl yapılacağını gösterir.', 'ai-hazir-site' ) . '</p><ul id="aihs-wizard-manual">';
			foreach ( $plan['manual'] as $step ) {
				$html .= '<li><strong>' . esc_html( $step->title ) . '</strong> (' . esc_html( self::gain( $step ) ) . ') – ' . esc_html( $step->description ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( $journal->started() ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-wizard-finish">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::FINISH ) . '">'
				. wp_nonce_field( self::FINISH, '_wpnonce', true, false )
				. get_submit_button( __( 'Bitir ve yeniden tara', 'ai-hazir-site' ) ) . '</form>';
		}

		return $html;
	}

	/**
	 * One applicable step with its short form.
	 *
	 * @param FixStep                                                                                   $step  Step.
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state Carried-over state.
	 */
	private static function action( FixStep $step, array $state ): string {
		$input  = ( $state['input']['step'] ?? '' ) === $step->id ? $state['input'] : array();
		$fields = '';

		if ( FixStep::PROFILE === $step->id ) {
			$profile = ( new WpProfileRepository() )->get();
			foreach ( array(
				'name'          => array( __( 'Firma adı', 'ai-hazir-site' ), $profile->name ),
				'sector'        => array( __( 'Sektör', 'ai-hazir-site' ), $profile->sector ),
				'country'       => array( __( 'Ülke (ör. TR)', 'ai-hazir-site' ), $profile->country ),
				'contact_email' => array( __( 'Kurumsal e-posta', 'ai-hazir-site' ), $profile->contact_email ),
			) as $name => [ $label, $value ] ) {
				$fields .= self::input( $name, $label, (string) ( $input[ $name ] ?? $value ) );
			}
		} elseif ( FixStep::FIRST_LISTING === $step->id ) {
			$options = '';
			foreach ( CatalogAdmin::type_labels() as $type => $label ) {
				$options .= '<option value="' . esc_attr( $type ) . '"' . selected( (string) ( $input['type'] ?? ListingType::OFFER ), $type, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$fields .= '<p><label>' . esc_html__( 'Tür', 'ai-hazir-site' ) . ' <select name="type">' . $options . '</select></label></p>';
			foreach ( array(
				'title'       => __( 'Başlık', 'ai-hazir-site' ),
				'description' => __( 'Kısa açıklama', 'ai-hazir-site' ),
				'price_min'   => __( 'Fiyat (isteğe bağlı)', 'ai-hazir-site' ),
				'currency'    => __( 'Para birimi (ör. TRY)', 'ai-hazir-site' ),
			) as $name => $label ) {
				$fields .= self::input( $name, $label, (string) ( $input[ $name ] ?? '' ) );
			}
		} elseif ( FixStep::BOTS === $step->id ) {
			$labels  = array(
				Presets::ALLOW_ALL       => __( 'Hepsine izin ver (önerilen)', 'ai-hazir-site' ),
				Presets::SEARCH_AND_USER => __( 'Sadece arama ve kullanıcı agentları', 'ai-hazir-site' ),
				Presets::BLOCK_TRAINING  => __( 'Eğitim botlarını engelle', 'ai-hazir-site' ),
			);
			$options = '';
			foreach ( $labels as $preset => $label ) {
				$options .= '<option value="' . esc_attr( $preset ) . '"' . selected( (string) ( $input['preset'] ?? Presets::ALLOW_ALL ), $preset, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$fields .= '<p><label>' . esc_html__( 'Hazır ayar', 'ai-hazir-site' ) . ' <select name="preset">' . $options . '</select></label></p>';
		}

		return '<div class="card aihs-wizard-step" id="aihs-step-' . esc_attr( $step->id ) . '"><h3>' . esc_html( $step->title ) . '</h3>'
			. '<p>' . esc_html( $step->description ) . '</p><p><strong>' . esc_html( self::gain( $step ) ) . '</strong></p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '"><input type="hidden" name="step" value="' . esc_attr( $step->id ) . '">'
			. wp_nonce_field( self::APPLY . '_' . $step->id, '_wpnonce', true, false )
			. $fields
			. get_submit_button( __( 'Uygula', 'ai-hazir-site' ), 'primary', 'submit', false )
			. '</form></div>';
	}

	/**
	 * Text input row.
	 *
	 * @param string $name  Name.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function input( string $name, string $label, string $value ): string {
		return '<p><label>' . esc_html( $label ) . ' <input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></label></p>';
	}

	/**
	 * "+12 puan", "en fazla +10 puan" or the prerequisite note.
	 *
	 * @param FixStep $step Step.
	 */
	public static function gain( FixStep $step ): string {
		if ( in_array( $step->id, array( FixStep::PROFILE, FixStep::FIRST_LISTING ), true ) ) {
			return __( 'Tek başına puan getirmez; sonraki adımlar için gerekli.', 'ai-hazir-site' );
		}
		if ( FixStep::SCAN === $step->id ) {
			return __( 'Başlangıç puanını ölçer.', 'ai-hazir-site' );
		}
		$points = rtrim( rtrim( number_format( $step->gain, 1, ',', '' ), '0' ), ',' );
		/* translators: %s: points. */
		return sprintf( $step->gain_is_max ? __( 'Tahmini kazanç: en fazla +%s puan', 'ai-hazir-site' ) : __( 'Tahmini kazanç: +%s puan', 'ai-hazir-site' ), $points );
	}

	/**
	 * Score before the wizard and now, per check.
	 *
	 * @param ScoreReport $before Baseline.
	 * @param ScoreReport $after  Latest scan.
	 */
	public static function comparison( ScoreReport $before, ScoreReport $after ): string {
		$html = '<h2>' . esc_html__( 'Önce / sonra', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-wizard-comparison"><thead><tr><th>'
			. esc_html__( 'Kriter', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Önce', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Sonra', 'ai-hazir-site' ) . '</th></tr></thead><tbody>';
		$cell = static fn( ?array $row ): string => null === $row || null === $row['ratio'] ? '–' : rtrim( rtrim( number_format( $row['points'], 1, ',', '' ), '0' ), ',' ) . ' / ' . $row['weight'];
		foreach ( CompliancePage::labels() as $id => $label ) {
			$html .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $cell( $before->result( $id ) ) ) . '</td><td>' . esc_html( $cell( $after->result( $id ) ) ) . '</td></tr>';
		}
		$score = static fn( ?int $s ): string => null === $s ? '–' : (string) $s;
		return $html . '<tr><th>' . esc_html__( 'Puan', 'ai-hazir-site' ) . '</th><th id="aihs-score-before">' . esc_html( $score( $before->score() ) ) . '</th><th id="aihs-score-after">' . esc_html( $score( $after->score() ) ) . '</th></tr></tbody></table>';
	}

	/**
	 * Step names for the applied list.
	 *
	 * @return array<string, string>
	 */
	public static function step_names(): array {
		return array(
			FixStep::SCAN          => __( 'Uyum taraması açıldı', 'ai-hazir-site' ),
			FixStep::PROFILE       => __( 'Firma profili kaydedildi', 'ai-hazir-site' ),
			FixStep::FIRST_LISTING => __( 'İlk ilan eklendi', 'ai-hazir-site' ),
			FixStep::SCHEMA        => __( 'Schema.org çıktısı açıldı', 'ai-hazir-site' ),
			FixStep::LLMS          => __( 'llms.txt açıldı', 'ai-hazir-site' ),
			FixStep::BOTS          => __( 'AI bot erişim ayarı kaydedildi', 'ai-hazir-site' ),
		);
	}

	/**
	 * `admin_post_aihs_wizard_apply`.
	 */
	public function on_apply(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_apply().
		self::redirect( $this->handle_apply( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_wizard_undo`.
	 */
	public function on_undo(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_undo().
		self::redirect( $this->handle_undo( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_wizard_finish`.
	 */
	public function on_finish(): void {
		self::redirect( $this->handle_finish() );
	}

	/**
	 * `admin_post_aihs_wizard_dismiss`.
	 */
	public function on_dismiss(): void {
		self::redirect( $this->handle_dismiss() );
	}

	/**
	 * Applies a step; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_apply( array $post ): string {
		$step = isset( $post['step'] ) && is_string( $post['step'] ) ? sanitize_key( $post['step'] ) : '';
		self::authorize( self::APPLY . '_' . $step );

		$input = array();
		foreach ( array( 'name', 'sector', 'country', 'contact_email', 'type', 'title', 'description', 'price_min', 'currency', 'preset' ) as $field ) {
			if ( isset( $post[ $field ] ) && is_scalar( $post[ $field ] ) ) {
				$input[ $field ] = 'contact_email' === $field ? sanitize_email( (string) $post[ $field ] ) : sanitize_text_field( (string) $post[ $field ] );
			}
		}

		$journal = WizardModule::journal();
		$latest  = ComplianceModule::store()->latest();
		$errors  = WizardModule::wizard()->apply( $step, $input );
		if ( array() !== $errors ) {
			FormState::put( $errors, array_merge( $input, array( 'step' => $step ) ) );
			return self::url();
		}

		if ( FixStep::SCAN === $step ) {
			self::allow_time();
			$latest = ComplianceModule::run();
		}
		if ( null !== $latest ) {
			$journal->set_baseline( $latest );
		}
		return self::url( array( 'applied' => $step ) );
	}

	/**
	 * Undoes a step; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_undo( array $post ): string {
		$step = isset( $post['step'] ) && is_string( $post['step'] ) ? sanitize_key( $post['step'] ) : '';
		self::authorize( self::UNDO . '_' . $step );

		$error = WizardModule::wizard()->undo( $step );
		if ( null !== $error ) {
			FormState::put( array( 'undo' => $error ) );
		}
		return self::url( null === $error ? array( 'undone' => $step ) : array() );
	}

	/**
	 * Scans again; the page then shows before / after.
	 */
	public function handle_finish(): string {
		self::authorize( self::FINISH );
		self::allow_time();
		ComplianceModule::run();
		return self::url( array( 'finished' => 1 ) );
	}

	/**
	 * Hides the first-run suggestion.
	 */
	public function handle_dismiss(): string {
		self::authorize( self::DISMISS );
		WizardModule::journal()->dismiss();
		return admin_url();
	}

	/**
	 * Suggests the wizard until it is started or dismissed (not on its own page).
	 */
	public static function suggestion(): void {
		$journal = WizardModule::journal();
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( self::CAPABILITY ) || $journal->started() || $journal->dismissed() || ( null !== $screen && str_contains( (string) $screen->id, self::SLUG ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info" id="aihs-wizard-suggestion"><p>%s <a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
			esc_html__( 'AI Hazır Site: Sitenizin AI uyumunu adım adım tamamlamak ister misiniz? Zorunlu değil; her adım geri alınabilir.', 'ai-hazir-site' ),
			esc_url( self::url() ),
			esc_html__( 'Sihirbazı aç', 'ai-hazir-site' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS ), self::DISMISS ) ),
			esc_html__( 'Bir daha gösterme', 'ai-hazir-site' )
		);
	}

	/**
	 * Capability and nonce check; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	public static function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * A scan may take up to 60 s.
	 */
	private static function allow_time(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 90 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- The scan may take up to 60 s.
		}
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
