<?php
/**
 * Polylang function stubs for PHPStan (signatures from https://polylang.pro/doc/function-reference/).
 * Never loaded at runtime.
 *
 * @package AIHazirSite
 */

// phpcs:ignoreFile -- Stub file for static analysis only.

/**
 * @param array<string, mixed> $args Arguments (fields, hide_empty).
 * @return array<mixed>
 */
function pll_languages_list( $args = array() ) {}

/**
 * @param string $field Field (slug, locale, name).
 * @return string|false
 */
function pll_default_language( $field = 'slug' ) {}

/**
 * @param string $field Field (slug, locale, name).
 * @return string|false
 */
function pll_current_language( $field = 'slug' ) {}
