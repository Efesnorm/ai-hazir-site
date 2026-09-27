<?php
/**
 * All sector templates.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Templates;

use AIHazirSite\Core\Measurement\Registry;
use InvalidArgumentException;
use JsonException;

/**
 * Reads every *.json file of the given folders (first folder first, files by name).
 * Adding a sector is adding a file: no code change. Invalid files and duplicate ids are
 * skipped and reported by errors(). "general" always exists (built in when its file is missing),
 * and an unknown id resolves to it, so listings of a removed template keep working.
 */
final class TemplateRegistry {

	/**
	 * Templates by id, "general" first.
	 *
	 * @var array<string, Template>
	 */
	private array $templates = array();

	/**
	 * Problems by file path.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $directories Folders to read.
	 *
	 * @phpstan-param list<string> $directories
	 */
	public function __construct( array $directories ) {
		$this->templates[ Template::GENERAL ] = Template::general();
		$seen                                 = array();

		foreach ( $directories as $directory ) {
			$files = glob( rtrim( $directory, '/\\' ) . '/*.json' );
			foreach ( false === $files ? array() : $files as $file ) {
				try {
					$template = Template::from_array( json_decode( (string) file_get_contents( $file ), true, 32, JSON_THROW_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin data.
				} catch ( JsonException $e ) {
					$this->errors[ $file ] = 'JSON okunamadı: ' . $e->getMessage();
					continue;
				} catch ( InvalidArgumentException $e ) {
					$this->errors[ $file ] = $e->getMessage();
					continue;
				}
				if ( isset( $seen[ $template->id ] ) ) {
					$this->errors[ $file ] = sprintf( '"%s" kimliği başka bir dosyada zaten tanımlı', $template->id );
					continue;
				}
				$seen[ $template->id ]            = true;
				$this->templates[ $template->id ] = $template;
			}
		}
	}

	/**
	 * Templates shipped in data/templates.
	 */
	public static function data_dir(): string {
		return Registry::data_dir() . '/templates';
	}

	/**
	 * Every template, "general" first.
	 *
	 * @return array<string, Template>
	 */
	public function all(): array {
		return $this->templates;
	}

	/**
	 * Whether a template with this id exists.
	 *
	 * @param string $id Id.
	 */
	public function has( string $id ): bool {
		return isset( $this->templates[ $id ] );
	}

	/**
	 * The template, or "general" for an empty or unknown id.
	 *
	 * @param string $id Id.
	 */
	public function get( string $id ): Template {
		return $this->templates[ $id ] ?? $this->templates[ Template::GENERAL ];
	}

	/**
	 * Files that could not be loaded, with the reason.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
