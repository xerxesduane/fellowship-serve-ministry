<?php
/**
 * The email confirmation link.
 *
 * A submission is invisible to every leader until the address is proven, because
 * the intake endpoint is anonymous: without this, anybody could put somebody
 * else's name and email into the journey and have a profile appear in a pastor's
 * queue attributed to them.
 *
 * The four outcomes and their wording come from Verification::messages() rather
 * than from this file. An earlier version of this view wrote its own copy for
 * two of them, which lost the distinction between a link that had expired and
 * one that could not be read at all -- and those need different advice, because
 * only one of them is the email client's fault.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve_Dashboard\Privacy;
use Serve_Dashboard\Verification;

nocache_headers();

$serve_result   = Verification::consume( (string) ( $_GET['token'] ?? '' ) );
$serve_messages = Verification::messages();
$serve_message  = $serve_messages[ $serve_result ] ?? $serve_messages[ Verification::RESULT_INVALID ];

// A failed confirmation is not a 200: it is a request that did not do what it
// asked to do, and monitoring should be able to see that.
if ( 'ok' !== $serve_message['tone'] ) {
	http_response_code( 400 );
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( (string) $serve_message['title'] ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( App::url( 'assessment/styles.css' ) ); ?>">
</head>
<body class="serve-canvas serve-canvas--message">

<main class="serve-confirm serve-confirm--<?php echo esc_attr( (string) $serve_message['tone'] ); ?>">
	<h1><?php echo esc_html( (string) $serve_message['title'] ); ?></h1>
	<p><?php echo esc_html( (string) $serve_message['body'] ); ?></p>

	<?php
	/*
	 * The likeliest moment for a question.
	 *
	 * Somebody has just confirmed that a small number of ministry leaders may
	 * read their spiritual gifts and their pastoral history, and the next thing
	 * that happens is silence until a leader gets in touch. Saying here that a
	 * person exists, and how to reach them, costs one line.
	 *
	 * The address comes from Privacy::contact_email(), so Settings governs it,
	 * and through antispambot() for the same reason the privacy notice does.
	 */
	$serve_contact = antispambot( Privacy::contact_email() );
	?>
	<p class="serve-confirm__ask">
		<?php
		printf(
			/* translators: %s: mailto link to the SERVE team. */
			esc_html__( 'Any questions about this, or about your results? Write to %s and a person will reply.' ),
			'<a href="mailto:' . esc_attr( $serve_contact ) . '">' . esc_html( $serve_contact ) . '</a>'
		);
		?>
	</p>

	<p>
		<a href="<?php echo esc_url( App::url( 'privacy' ) ); ?>">
			<?php esc_html_e( 'How we look after your answers' ); ?>
		</a>
	</p>
</main>

</body>
</html>
