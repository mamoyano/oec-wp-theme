<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   HELPER: llamar a la API de OEC
   ============================================================ */
function oec_fetch_trainings( string $query ): array {
	$token = oec_get_options()['oec_api_token'] ?? '';

	if ( ! $token || strlen( trim( $query ) ) < 2 ) {
		return [];
	}

	$response = wp_remote_get(
		'https://oas-api.onlineeducation.center/api-oas/v1/trainings?search-term=' . urlencode( $query ),
		[
			'headers' => [
				'X-API-TOKEN' => $token,
				'Accept'      => 'application/json',
			],
			'timeout' => 10,
		]
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return [];
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return $body['data'] ?? [];
}

/* ============================================================
   REST ENDPOINT: GET /wp-json/oec/v1/search?q=genetica
   Lo llama el JS del buscador del header.
   El token nunca sale al navegador.
   ============================================================ */
function oec_register_search_route(): void {
	register_rest_route( 'oec/v1', '/search', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'oec_rest_search_handler',
		'permission_callback' => '__return_true',
		'args'                => [
			'q' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) >= 2,
			],
		],
	] );
}
add_action( 'rest_api_init', 'oec_register_search_route' );

function oec_rest_search_handler( WP_REST_Request $request ): WP_REST_Response {
	$results = oec_fetch_trainings( $request->get_param( 'q' ) );
	return new WP_REST_Response( [ 'data' => $results ], 200 );
}
