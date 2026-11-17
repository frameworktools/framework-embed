<?php
/**
 * Creates the posts the e2e checks read. Runs inside Playground from blueprint.json.
 *
 * @package FrameworkEmbed
 */

$framework_embed_posts = array(
	// Classic editor: a URL on its own line, wpautop applies.
	'classic'     => "Before the model.\n\nhttps://framework.pub/d/minimal-model\n\nAfter the model.",
	// The framework/artifact block.
	'block'       => '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph --><!-- wp:framework/artifact {"url":"https://framework.pub/d/r-glm-logit"} /--><!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->',
	// A URL pasted in the block editor becomes a core/embed block.
	'embed-block' => "<!-- wp:embed {\"url\":\"https://framework.pub/@ada/admissions/r-tibble\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://framework.pub/@ada/admissions/r-tibble\n</div></figure>\n<!-- /wp:embed -->",
	// The shortcode, with a chart so the script is enqueued.
	'shortcode'   => "Chart:\n\n[framework_artifact url=\"https://framework.pub/d/python-altair/versions/2\"]\n\nEnd.",
	// A gone artifact, an unreachable server, and a host that is not allowed.
	'missing'     => "Gone:\n\nhttps://framework.pub/d/gone\n\nDown:\n\nhttps://framework.pub/d/down\n\nElsewhere:\n\nhttps://evil.example/d/minimal-model\n\nEnd.",
	// A self-hosted server, embedded only after an administrator allows its host.
	'extra-host'  => "Self-hosted:\n\nhttps://framework.example.org/d/minimal-test\n\nEnd.",
	// Every fixture through the shortcode, checked byte for byte against spec/fixtures/*.html.
	'all'         => implode(
		"\n\n",
		array_map(
			static function ( $file ) {
				return '[framework_artifact url="https://framework.pub/d/' . basename( $file, '.json' ) . '"]';
			},
			glob( '/fixtures/*.json' )
		)
	),
);

foreach ( $framework_embed_posts as $framework_embed_slug => $framework_embed_content ) {
	wp_insert_post(
		array(
			'post_name'    => $framework_embed_slug,
			'post_title'   => ucfirst( $framework_embed_slug ),
			'post_content' => wp_slash( $framework_embed_content ),
			'post_status'  => 'publish',
		)
	);
}
