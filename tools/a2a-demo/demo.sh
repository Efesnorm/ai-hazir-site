#!/usr/bin/env bash
# A2A iki site demosu (yerel, gerçek HTTPS). Kullanım:
#   tools/a2a-demo/demo.sh            kur ve çalıştır (her seferinde temiz başlar)
#   tools/a2a-demo/demo.sh temizle    kapsayıcıları ve verileri sil
set -euo pipefail
cd "$(dirname "$0")"
# Git Bash (Windows) /demo/... yollarını çevirmesin.
export MSYS_NO_PATHCONV=1

dc() { docker compose --progress quiet "$@"; }
wp_site() { local site="$1"; shift; dc run --rm -T "cli-${site}" wp "$@"; }

if [ "${1:-}" = "temizle" ]; then
	dc --profile cli down -v --remove-orphans
	exit 0
fi

echo "== Temiz başlangıç"
dc --profile cli down -v --remove-orphans >/dev/null 2>&1 || true
dc up -d

echo "== Veritabanı ve WordPress dosyaları bekleniyor"
for i in $(seq 1 60); do
	if dc exec -T db mariadb -udemo -pdemo -e 'SELECT 1' fabrika >/dev/null 2>&1 \
		&& dc exec -T dagitici test -f /var/www/html/wp-config.php \
		&& dc exec -T fabrika test -f /var/www/html/wp-config.php; then
		break
	fi
	sleep 2
done

# Yerel demo yöneticisinin parolası her çalıştırmada rastgele üretilir ve gösterilmez.
parola="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
for site in fabrika dagitici; do
	echo "== ${site}.test kuruluyor"
	wp_site "$site" core install --url="https://${site}.test" --title="Demo ${site}" \
		--admin_user=admin --admin_password="$parola" --admin_email="admin@${site}.test" --skip-email >/dev/null
	wp_site "$site" plugin activate ai-hazir-site >/dev/null
done

wp_site fabrika --user=admin eval-file /demo/adimlar.php fabrika-kur
wp_site dagitici --user=admin eval-file /demo/adimlar.php dagitici-kur

echo "== Fabrikanın agent kartı dağıtıcıdan (HTTPS) okunuyor"
wp_site dagitici eval 'echo wp_remote_retrieve_body( wp_remote_get( "https://fabrika.test/.well-known/agent-card.json" ) );' | head -c 400
echo

wp_site dagitici --user=admin eval-file /demo/adimlar.php gonder
wp_site fabrika --user=admin eval-file /demo/adimlar.php fabrika-kontrol

echo
echo "== Demo başarılı: dağıtıcının agent'ı, insan onayıyla fabrikanın agent'ına teklif isteği bıraktı."
echo "   Kapsayıcıları silmek için: tools/a2a-demo/demo.sh temizle"
