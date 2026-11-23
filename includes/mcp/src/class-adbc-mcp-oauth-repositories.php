<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ADBC\Vendor\League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use ADBC\Vendor\League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use ADBC\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use ADBC\Vendor\League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use ADBC\Vendor\League\OAuth2\Server\Entities\ScopeEntityInterface;
use ADBC\Vendor\League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use ADBC\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;
use ADBC\Vendor\League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use ADBC\Vendor\League\OAuth2\Server\Entities\Traits\ScopeTrait;
use ADBC\Vendor\League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use ADBC\Vendor\League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use ADBC\Vendor\League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use ADBC\Vendor\League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use ADBC\Vendor\League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use ADBC\Vendor\League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use ADBC\Vendor\League\OAuth2\Server\CryptKeyInterface;
use ADBC\Vendor\Lcobucci\JWT\Configuration;
use ADBC\Vendor\Lcobucci\JWT\Signer\Key\InMemory;
use ADBC\Vendor\Lcobucci\JWT\Signer\Rsa\Sha256;

use ADBC\Vendor\League\OAuth2\Server\Entities\UserEntityInterface;

final class ADBC_MCP_OAuth_User implements UserEntityInterface {
	public function __construct( private int $id ) {
	}
	public function getIdentifier(): string {
		return (string) $this->id;
	}
}

final class ADBC_MCP_OAuth_Client implements ClientEntityInterface {
	use EntityTrait;
	private string $name;
	private array $redirects;

	public function __construct( array $row ) {
		$this->identifier = $row['id'];
		$this->name = $row['name'];
		$this->redirects = json_decode( $row['redirect_uris'], true ) ?: [];
	}
	public function getName(): string {
		return $this->name;
	}
	public function getRedirectUri(): string|array {
		return $this->redirects;
	}
	public function isConfidential(): bool {
		return false;
	}
}

final class ADBC_MCP_OAuth_Scope implements ScopeEntityInterface {
	use EntityTrait;
	use ScopeTrait;
	public function __construct( string $id ) {
		$this->identifier = $id;
	}
}

final class ADBC_MCP_OAuth_AccessToken implements AccessTokenEntityInterface {
	use EntityTrait;
	use TokenEntityTrait;
	private CryptKeyInterface $privateKey;
	public function setPrivateKey( CryptKeyInterface $privateKey ): void {
		$this->privateKey = $privateKey;
	}
	public function toString(): string {
		$config = Configuration::forAsymmetricSigner( new Sha256(), InMemory::plainText( $this->privateKey->getKeyContents() ), InMemory::plainText( 'unused' ) );
		$now = new DateTimeImmutable();
		$scopes = array_map( static fn( $scope ) => $scope->getIdentifier(), $this->getScopes() );
		return $config->builder()
			->issuedBy( ADBC_MCP_OAuth::issuer() )
			->permittedFor( ADBC_MCP_OAuth::resource() )
			->identifiedBy( $this->identifier )
			->relatedTo( (string) $this->userIdentifier )
			->issuedAt( $now )
			->canOnlyBeUsedAfter( $now )
			->expiresAt( $this->expiryDateTime )
			->withClaim( 'client_id', $this->client->getIdentifier() )
			->withClaim( 'scopes', $scopes )
			->getToken( $config->signer(), $config->signingKey() )
			->toString();
	}
}

final class ADBC_MCP_OAuth_AuthCode implements AuthCodeEntityInterface {
	use EntityTrait;
	use TokenEntityTrait;
	use AuthCodeTrait;
}

final class ADBC_MCP_OAuth_RefreshToken implements RefreshTokenEntityInterface {
	use EntityTrait;
	use RefreshTokenTrait;
}

final class ADBC_MCP_OAuth_Clients implements ClientRepositoryInterface {
	/** A request-local client is supplied only by the authenticated authorization flow. */
	public function __construct( private ?array $authorization_client = null ) {
	}
	public function getClientEntity( string $clientIdentifier ): ?ClientEntityInterface {
		$row = ADBC_MCP_Store::get( 'clients', $clientIdentifier );
		if ( $row && empty( $row['enabled'] ) ) {
			return null;
		}
		if ( $this->authorization_client && $this->authorization_client['id'] === $clientIdentifier ) {
			$row = $this->authorization_client;
		}
		return $row && 'metadata' === ( $row['kind'] ?? '' ) ? new ADBC_MCP_OAuth_Client( $row ) : null;
	}
	public function validateClient( string $clientIdentifier, ?string $clientSecret, ?string $grantType ): bool {
		$row = ADBC_MCP_Store::get( 'clients', $clientIdentifier );
		if ( ! $row || 'metadata' !== ( $row['kind'] ?? '' ) || empty( $row['enabled'] ) || ! in_array( $grantType, [ 'authorization_code', 'refresh_token' ], true ) ) {
			return false;
		}
		return null === $clientSecret || '' === $clientSecret;
	}
}

final class ADBC_MCP_OAuth_Scopes implements ScopeRepositoryInterface {
	public function getScopeEntityByIdentifier( string $identifier ): ?ScopeEntityInterface {
		return in_array( $identifier, [ 'adbc:use', 'offline_access' ], true ) ? new ADBC_MCP_OAuth_Scope( $identifier ) : null;
	}
	public function finalizeScopes( array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null ): array {
		$ids = array_map( static fn( $scope ) => $scope->getIdentifier(), $scopes );
		if ( ! in_array( 'adbc:use', $ids, true ) ) {
			$scopes[] = new ADBC_MCP_OAuth_Scope( 'adbc:use' );
		}
		return $scopes;
	}
}

/** One repository stores only token identifiers, never bearer secrets. */
final class ADBC_MCP_OAuth_Tokens implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface {
	private ?array $exchange_code = null;
	private ?string $claimed_code = null;
	public function getNewToken( ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null ): AccessTokenEntityInterface {
		$token = new ADBC_MCP_OAuth_AccessToken();
		$token->setIdentifier( bin2hex( random_bytes( 32 ) ) );
		$token->setClient( $clientEntity );
		if ( null !== $userIdentifier ) {
			$token->setUserIdentifier( $userIdentifier );
		}
		foreach ( $scopes as $scope ) {
			$token->addScope( $scope );
		}
		return $token;
	}
	public function getNewAuthCode(): AuthCodeEntityInterface {
		$code = new ADBC_MCP_OAuth_AuthCode();
		$code->setIdentifier( bin2hex( random_bytes( 32 ) ) );
		return $code;
	}
	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		$token = new ADBC_MCP_OAuth_RefreshToken();
		$token->setIdentifier( bin2hex( random_bytes( 32 ) ) );
		return $token;
	}
	private function persist( string $id, string $kind, string $grant, string $client, int $user, DateTimeImmutable $expires, array $payload = [] ): void {
		$saved = ADBC_MCP_Store::add( 'tokens', [
			'id' => hash( 'sha256', $id ), 'kind' => $kind, 'grant_id' => $grant, 'client_id' => $client,
			'user_id' => $user, 'resource' => ADBC_MCP_OAuth::resource(), 'payload' => wp_json_encode( $payload ),
			'expires_at' => $expires->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'revoked' => 0,
		] );
		if ( ! $saved ) {
			throw new RuntimeException( 'Could not persist OAuth token state.' );
		}
	}
	private function row( string $id, string $kind ): ?array {
		$row = ADBC_MCP_Store::get( 'tokens', hash( 'sha256', $id ) );
		return $row && ( $row['kind'] ?? null ) === $kind && is_string( $row['grant_id'] ?? null ) && '' !== $row['grant_id']
			&& in_array( $row['revoked'] ?? null, [ 0, 1 ], true ) && ADBC_MCP_Store::valid_expiry( $row['expires_at'] ?? null )
			&& is_string( $row['resource'] ?? null ) ? $row : null;
	}
	private function valid( string $id, string $kind ): bool {
		$row = $this->row( $id, $kind );
		if ( ! $row || (int) $row['revoked'] !== 0 || strtotime( $row['expires_at'] . ' UTC' ) < time() || ! ADBC_MCP_Store::grant( $row['grant_id'] ) ) {
			return false;
		}
		if ( 'access' !== $kind && ! hash_equals( $row['resource'], ADBC_MCP_OAuth::resource() ) ) {
			return false;
		}
		if ( 'access' !== $kind ) {
			ADBC_MCP_OAuth::set_exchange_grant( $row['grant_id'] );
		}
		if ( 'code' === $kind ) {
			$this->exchange_code = $row;
		}
		return true;
	}
	private function revoke( string $id, string $kind ): void {
		if ( 'code' === $kind && $this->claimed_code === hash( 'sha256', $id ) ) {
			return;
		}
		$row = $this->row( $id, $kind );
		// Refresh tokens can outlive their access-token row. Missing access state
		// is already unusable; its revocation is idempotent after expiry cleanup.
		if ( ! $row && 'access' === $kind ) {
			return;
		}
		if ( $row && ! empty( $row['revoked'] ) && 'access' === $kind ) {
			return;
		}
		if ( ! $row || ! empty( $row['revoked'] ) || ! ADBC_MCP_Store::replace( 'tokens', $row, array_replace( $row, [ 'revoked' => 1 ] ) ) ) {
			throw new RuntimeException( 'OAuth token was already used or could not be revoked.' );
		}
	}
	public function persistNewAccessToken( AccessTokenEntityInterface $accessTokenEntity ): void {
		// League revokes authorization codes after minting. Claim before persisting
		// so simultaneous exchanges cannot both produce usable access tokens.
		if ( $this->exchange_code ) {
			$row = $this->exchange_code;
			if ( ! ADBC_MCP_Store::replace( 'tokens', $row, array_replace( $row, [ 'revoked' => 1 ] ) ) ) {
				throw new RuntimeException( 'Authorization code was already used.' );
			}
			$this->claimed_code = $row['id'];
			$this->exchange_code = null;
		}
		$this->persist( $accessTokenEntity->getIdentifier(), 'access', ADBC_MCP_OAuth::exchange_grant(), $accessTokenEntity->getClient()->getIdentifier(), (int) $accessTokenEntity->getUserIdentifier(), $accessTokenEntity->getExpiryDateTime() );
	}
	public function revokeAccessToken( string $tokenId ): void {
		$this->revoke( $tokenId, 'access' );
	}
	public function isAccessTokenRevoked( string $tokenId ): bool {
		return ! $this->valid( $tokenId, 'access' );
	}
	public function persistNewAuthCode( AuthCodeEntityInterface $authCodeEntity ): void {
		$this->persist( $authCodeEntity->getIdentifier(), 'code', ADBC_MCP_OAuth::exchange_grant(), $authCodeEntity->getClient()->getIdentifier(), (int) $authCodeEntity->getUserIdentifier(), $authCodeEntity->getExpiryDateTime() );
	}
	public function revokeAuthCode( string $codeId ): void {
		$this->revoke( $codeId, 'code' );
	}
	public function isAuthCodeRevoked( string $codeId ): bool {
		return ! $this->valid( $codeId, 'code' );
	}
	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		$access = $refreshTokenEntity->getAccessToken();
		$this->persist( $refreshTokenEntity->getIdentifier(), 'refresh', ADBC_MCP_OAuth::exchange_grant(), $access->getClient()->getIdentifier(), (int) $access->getUserIdentifier(), $refreshTokenEntity->getExpiryDateTime(), [ 'access_id' => $access->getIdentifier() ] );
	}
	public function revokeRefreshToken( string $tokenId ): void {
		$this->revoke( $tokenId, 'refresh' );
	}
	public function isRefreshTokenRevoked( string $tokenId ): bool {
		return ! $this->valid( $tokenId, 'refresh' );
	}
	public function access_row( string $id ): ?array {
		return $this->row( $id, 'access' );
	}
}
