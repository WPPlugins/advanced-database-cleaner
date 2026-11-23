<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Compact OAuth consent, using the plugin's branding and compiled Tailwind stylesheet. */
?>
<!doctype html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Connect to Advanced Database Cleaner', 'advanced-database-cleaner' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $stylesheet_url ); ?>">
</head>

<body class="bg-slate-100 font-sans text-slate-800">
	<div id="adbc-plugin-root" class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6">
		<main
			class="w-full max-w-[600px] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl shadow-slate-200/70"
			aria-labelledby="adbc-consent-title">
			<header
				class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 bg-[#087fab] px-6 py-5 text-white sm:gap-4 sm:px-8 sm:py-6">
				<div class="flex min-w-0 flex-col items-start gap-2.5 sm:flex-row sm:items-center sm:gap-3">
					<div
						class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/15">
						<img src="<?php echo esc_url( $asset_url . '/images/logo.png' ); ?>" alt="" class="h-9 w-auto"
							width="31" height="40">
					</div>
					<div class="w-full min-w-0">
						<p class="break-words text-[13px] font-semibold leading-snug sm:text-sm">


							<?php esc_html_e( 'Advanced Database Cleaner', 'advanced-database-cleaner' ); ?>
						</p>
					</div>
				</div>
				<?php // Tabler Link and Robot icons, rendered from the plugin's react-icons/tb library. ?>
				<div class="relative flex w-9 items-center justify-center self-stretch sm:w-10" aria-hidden="true">
					<span
						class="absolute -bottom-5 -top-5 left-1/2 -translate-x-1/2 border-l border-dashed border-slate-100 sm:-bottom-6 sm:-top-6"></span>
					<span
						class="relative flex h-9 w-9 items-center justify-center rounded-full bg-[#2d92b5] text-sky-50 ring-1 ring-inset ring-white/25 sm:h-10 sm:w-10">
						<svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24"
							stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 rotate-45" aria-hidden="true"
							focusable="false" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
							<path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
							<path d="M9 15l6 -6"></path>
							<path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"></path>
							<path
								d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463">
							</path>
						</svg>
					</span>
				</div>
				<div
					class="flex min-w-0 flex-col items-end gap-2.5 sm:flex-row sm:items-center sm:justify-end sm:gap-3">
					<div class="order-2 w-full min-w-0 text-right sm:order-1">

						<p class="break-words text-sm font-semibold leading-
							snug sm:text-base">
							<?php echo esc_html( $client_name ); ?>
						</p>
						<p class="mt-1 text-[11px] text-sky-100 sm:text-xs">
							<?php esc_html_e( 'AI agent', 'advanced-database-cleaner' ); ?>
						</p>
					</div>
					<div class="order-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-inset ring-white/15 sm:order-2"
						aria-hidden="true">
						<svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24"
							stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6" aria-hidden="true"
							focusable="false" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
							<path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
							<path d="M6 4m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z">
							</path>
							<path d="M12 2v2"></path>
							<path d="M9 12v9"></path>
							<path d="M15 12v9"></path>
							<path d="M5 16l4 -2"></path>
							<path d="M15 14l4 2"></path>
							<path d="M9 18h6"></path>
							<path d="M10 8v.01"></path>
							<path d="M14 8v.01"></path>
						</svg>
					</div>
				</div>
			</header>
			<div class="p-6 sm:p-8">
				<h1 id="adbc-consent-title" class="text-[22px] font-semibold leading-7 tracking-tight text-slate-900">
					<?php esc_html_e( 'Connect your AI agent', 'advanced-database-cleaner' ); ?>
				</h1>
				<p class="mt-2 break-words text-sm leading-6 text-slate-600">
					<?php
					printf(
						/* translators: 1: AI client name, 2: WordPress site name. Both are emphasized. */
						esc_html__( '%1$s wants to connect to %2$s.', 'advanced-database-cleaner' ),
						'<strong class="font-semibold text-slate-800">' . esc_html( $client_name ) . '</strong>',
						'<strong class="font-semibold text-slate-800">' . esc_html( $site_name ) . '</strong>'
					);
					?>
				</p>
				<p class="mt-2 text-[13px] leading-5 text-slate-500">
					<?php esc_html_e( 'This connection lets your AI agent communicate with the Advanced Database Cleaner plugin and send commands using the access you select below.', 'advanced-database-cleaner' ); ?>
				</p>

				<p
					class="mt-5 break-words rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-5 text-slate-600">
					<?php
					printf(
						/* translators: 1: signed-in account name (emphasized), 2: account role. */
						esc_html__( 'You are signed in as %1$s (%2$s).', 'advanced-database-cleaner' ),
						'<strong class="font-semibold text-slate-800">' . esc_html( $account_name ) . '</strong>',
						esc_html( $account_role )
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( self::authorization_url() ); ?>" class="mt-5">
					<?php
					foreach ( self::consent_params( $params ) as $name => $value ) {
						if ( is_scalar( $value ) ) {
							echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
						}
					}
					wp_nonce_field( $consent_action );
					?>
					<input type="hidden" name="_adbc_client_fingerprint"
						value="<?php echo esc_attr( self::client_fingerprint( $client ) ); ?>">
					<fieldset aria-describedby="adbc-access-change-help">
						<legend class="mb-3 text-sm font-semibold text-slate-800">
							<?php esc_html_e( 'Choose access', 'advanced-database-cleaner' ); ?>
						</legend>
						<div class="grid gap-3 sm:grid-cols-2">
							<label
								class="flex cursor-pointer flex-col rounded-lg border border-slate-200 p-4 transition-colors hover:border-slate-300 has-[:checked]:border-primary has-[:checked]:bg-sky-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary has-[:focus-visible]:ring-offset-2">
								<span class="flex items-center justify-between gap-3">
									<span
										class="text-sm font-semibold"><?php esc_html_e( 'Full access', 'advanced-database-cleaner' ); ?></span>
									<input type="radio" name="write_enabled" value="1" checked
										aria-label="<?php esc_attr_e( 'Full access', 'advanced-database-cleaner' ); ?>"
										aria-describedby="adbc-full-access-help"
										class="h-4 w-4 shrink-0 accent-[#2271b1]">
								</span>
								<span id="adbc-full-access-help"
									class="mt-2 text-[13px] leading-5 text-slate-500"><?php esc_html_e( 'Read and modify data using available tools in your version. Modification actions still need separate approval in your AI chat.', 'advanced-database-cleaner' ); ?></span>
							</label>
							<label
								class="flex cursor-pointer flex-col rounded-lg border border-slate-200 p-4 transition-colors hover:border-slate-300 has-[:checked]:border-primary has-[:checked]:bg-sky-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary has-[:focus-visible]:ring-offset-2">
								<span class="flex items-center justify-between gap-3">
									<span
										class="text-sm font-semibold"><?php esc_html_e( 'Read-only', 'advanced-database-cleaner' ); ?></span>
									<input type="radio" name="write_enabled" value="0"
										aria-label="<?php esc_attr_e( 'Read-only', 'advanced-database-cleaner' ); ?>"
										aria-describedby="adbc-read-access-help"
										class="h-4 w-4 shrink-0 accent-[#2271b1]">
								</span>
								<span id="adbc-read-access-help"
									class="mt-2 text-[13px] leading-5 text-slate-500"><?php esc_html_e( 'Read database information and tool results, without making changes.', 'advanced-database-cleaner' ); ?></span>
							</label>
						</div>
						<p id="adbc-access-change-help" class="mt-3 text-xs leading-5 text-slate-500">
							<?php esc_html_e( 'You can change this access at any time from the MCP tab in the plugin.', 'advanced-database-cleaner' ); ?>
						</p>
					</fieldset>

					<div class="mt-5">
						<label for="adbc-connection-name"
							class="block text-sm font-medium text-slate-700"><?php esc_html_e( 'Connection name (optional)', 'advanced-database-cleaner' ); ?></label>
						<input id="adbc-connection-name" name="connection_name" type="text" maxlength="80" value=""
							placeholder="<?php echo esc_attr__( 'Work laptop · personal account', 'advanced-database-cleaner' ); ?>"
							class="mt-2 h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
					</div>
					<div class="mt-6 grid grid-cols-2 gap-3 sm:flex sm:justify-end">
						<button type="submit" name="decision" value="deny" formnovalidate
							class="inline-flex min-h-11 items-center justify-center rounded-md border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-w-24"><?php esc_html_e( 'Deny', 'advanced-database-cleaner' ); ?></button>
						<button type="submit" name="decision" value="approve"
							class="inline-flex min-h-11 items-center justify-center rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#1b5f97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-w-32"><?php esc_html_e( 'Approve', 'advanced-database-cleaner' ); ?></button>
					</div>
				</form>
			</div>
		</main>
	</div>
</body>

</html>
