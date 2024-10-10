<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudFederationAPI\Controller;

use OCA\CloudFederationAPI\Config;
use OCA\Federation\TrustedServers;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\Federation\Exceptions\ActionNotSupportedException;
use OCP\Federation\Exceptions\AuthenticationFailedException;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Open-Cloud-Mesh-API
 *
 * @package OCA\CloudFederationAPI\Controller
 *
 * @psalm-import-type CloudFederationAPIAddShare from ResponseDefinitions
 * @psalm-import-type CloudFederationAPIValidationError from ResponseDefinitions
 * @psalm-import-type CloudFederationAPIError from ResponseDefinitions
 */
#[OpenAPI(scope: OpenAPI::SCOPE_FEDERATION)]
class RequestHandlerController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private LoggerInterface $logger,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
		private ICloudFederationProviderManager $cloudFederationProviderManager,
		private IQueryBuilder $queryBuilder,
		private Config $config,
		private ICloudFederationFactory $factory,
		private ICloudIdManager $cloudIdManager,
		private TrustedServers $trustedServers
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Add share
	 *
	 * @param string $shareWith The user who the share will be shared with
	 * @param string $name The resource name (e.g. document.odt)
	 * @param string|null $description Share description
	 * @param string $providerId Resource UID on the provider side
	 * @param string $owner Provider specific UID of the user who owns the resource
	 * @param string|null $ownerDisplayName Display name of the user who shared the item
	 * @param string $sender Provider specific UID of the user who shared the resource
	 * @param string|null $senderDisplayName Display name of the user who shared the resource
	 * @param array{name: string[], options: array<string, mixed>} $protocol e,.g. ['name' => 'webdav', 'options' => ['username' => 'john', 'permissions' => 31]]
	 * @param string $shareType 'group' or 'user' share
	 * @param string $resourceType 'file', 'calendar',...
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, CloudFederationAPIAddShare, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, CloudFederationAPIValidationError, array{}>|JSONResponse<Http::STATUS_NOT_IMPLEMENTED, CloudFederationAPIError, array{}>
	 * 201: The notification was successfully received. The display name of the recipient might be returned in the body
	 * 400: Bad request due to invalid parameters, e.g. when `shareWith` is not found or required properties are missing
	 * 501: Share type or the resource type is not supported
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'receiveFederatedShare')]
	public function addShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sender, $senderDisplayName, $protocol, $shareType, $resourceType, $expiration): JSONResponse
	{
		$new_protocol = $this->normalizeProtocol($protocol);
		$protocol_valid = $this->validateProtocol($new_protocol);
		// check if all required parameters are set
		if (
			$shareWith === null ||
			$name === null ||
			$providerId === null ||
			$owner === null ||
			$sender === null ||
			$resourceType === null ||
			$shareType === null ||
			!$protocol_valid
		) {
			return new JSONResponse(
				[
					'message' => 'Missing arguments',
					'validationErrors' => [],
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$supportedShareTypes = $this->config->getSupportedShareTypes($resourceType);
		if (!in_array($shareType, $supportedShareTypes)) {
			return new JSONResponse(
				['message' => 'Share type "' . $shareType . '" not implemented'],
				Http::STATUS_NOT_IMPLEMENTED
			);
		}

		if ($shareType === 'user') {
			$shareWith = $this->mapUid($shareWith);
			$cloudId = $this->cloudIdManager->resolveCloudId($shareWith);
			$shareWith = $cloudId->getUser();

			if (!$this->userManager->userExists($shareWith)) {
				$response = new JSONResponse(
					[
						'message' => 'User "' . $shareWith . '" does not exists at ' . $this->urlGenerator->getBaseUrl(),
						'validationErrors' => [],
					],
					Http::STATUS_BAD_REQUEST
				);
				$response->throttle();
				return $response;
			}
		}

		if ($shareType === 'group') {
			if (!$this->groupManager->groupExists($shareWith)) {
				$response = new JSONResponse(
					[
						'message' => 'Group "' . $shareWith . '" does not exists at ' . $this->urlGenerator->getBaseUrl(),
						'validationErrors' => [],
					],
					Http::STATUS_BAD_REQUEST
				);
				$response->throttle();
				return $response;
			}
		}

		// if no explicit display name is given, we use the uid as display name
		$ownerDisplayName = $ownerDisplayName === null ? $owner : $ownerDisplayName;
		$senderDisplayName = $senderDisplayName === null ? $sender : $senderDisplayName;

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider($resourceType);

			$sharedSecret = $this->extractSharedSecret($new_protocol);
			$share = $this->factory->createCloudFederationShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sender, $senderDisplayName, $sharedSecret, $shareType, $resourceType, $expiration, $new_protocol);
			$provider->shareReceived($share);
		} catch (ProviderDoesNotExistsException | ProviderCouldNotAddShareException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_NOT_IMPLEMENTED
			);
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				[
					'message' => 'Internal error at ' . $this->urlGenerator->getBaseUrl(),
					'validationErrors' => [],
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$user = $this->userManager->get($shareWith);
		$recipientDisplayName = '';
		if ($user) {
			$recipientDisplayName = $user->getDisplayName();
		}

		return new JSONResponse(
			['recipientDisplayName' => $recipientDisplayName],
			Http::STATUS_CREATED
		);
	}

	/**
	 * Inform the sender that an invitation was accepted to start sharing
	 *
	 * Inform about an accepted invitation so the user on the sender provider's side
	 * can initiate the OCM share creation. To protect the identity of the parties,
	 * for shares created following an OCM invitation, the user id MAY be hashed,
	 * and recipients implementing the OCM invitation workflow MAY refuse to process
	 * shares coming from unknown parties.
	 *
	 * @param string $recipientProvider
	 * @param string $token
	 * @param string $userId
	 * @param string $email
	 * @param string $name
	 * @return JSONResponse
	 * 200: invitation accepted
	 * 400: Invalid token
	 * 403: Invitation token does not exist
	 * 409: User is allready known by the OCM provider
	 * spec link: https://cs3org.github.io/OCM-API/docs.html?branch=v1.1.0&repo=OCM-API&user=cs3org#/paths/~1invite-accepted/post
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'inviteAccepted')]
	public function inviteAccepted(string $recipientProvider, string $token, string $userId, string $email, string $name): JSONResponse
	{
		$this->logger->debug('Invite accepted for ' . $userId . ' with token ' . $token . ' and email ' . $email . ' and name ' . $name);
		$this->queryBuilder->select('id', 'sender', 'email', 'name', 'status')
			->from('federated_invites')
			->where('token', $this->queryBuilder->expr()->eq($token, $this->queryBuilder->createNamedParameter($token)))
			->andWhere('userId', $this->queryBuilder->expr()->eq($userId, $this->queryBuilder->createNamedParameter($userId)))
			->andWhere('recipientProvider', $this->queryBuilder->expr()->eq($recipientProvider, $this->queryBuilder->createNamedParameter($recipientProvider)))
		;
		$result = $this->queryBuilder->executeQuery();
		// If we found something, that means the invitation is valid
		$rows = $result->fetchAll();
		$id = $rows[0]['id'];
		$sender = $rows[0]['sender'];
		$email = $rows[0]['email'];
		$name = $rows[0]['name'];
		$status = $rows[0]['status'];
		$valid = $id and $sender and $email and $name and $status and (sizeof($rows) == 1);
		$result->closeCursor();

		if (!$valid) {
			$response = ['message' => 'Invalid or non existing token', 'error' => true];
			$status = Http::STATUS_BAD_REQUEST;
			return new JSONResponse($response, $status);
		}

		if (!$this->trustedServers->isTrustedServer($recipientProvider)) {
			$response = ['message' => 'Remote server not trusted', 'error' => true];
			$status = Http::STATUS_FORBIDDEN;
			return new JSONResponse($response, $status);
		}
		// Note: Not implementing 404 Invitation token does not exist, instead using 400

		$accepted = ['accepted', 'processed'];
		if (in_array($status, $accepted)) {
			$response = ['message' => 'Invite already accepted', 'error' => true];
			$status = Http::STATUS_CONFLICT;
			return new JSONResponse($response, $status);
		}

		$this->queryBuilder->update('federated_invites')
			->set('status', 'accepted')
			->where($this->queryBuilder->expr()->eq('id', $this->queryBuilder->createNamedParameter($id)))
			->executeStatement();
		$response = ['usedID' => $sender, 'email' => $email, 'name' => $name];
		$status = Http::STATUS_OK;

		return new JSONResponse($response, $status);
	}

	/**
	 *
	 * @param string $notificationType Notification type, e.g. SHARE_ACCEPTED
	 * @param string $resourceType calendar, file, contact,...
	 * @param string|null $providerId ID of the share
	 * @param array<string, mixed>|null $notification The actual payload of the notification
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, CloudFederationAPIValidationError, array{}>|JSONResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_IMPLEMENTED, CloudFederationAPIError, array{}>
	 * 201: The notification was successfully received
	 * 400: Bad request due to invalid parameters, e.g. when `type` is invalid or missing
	 * 403: Getting resource is not allowed
	 * 501: The resource type is not supported
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[BruteForceProtection(action: 'receiveFederatedShareNotification')]
	public function receiveNotification($notificationType, $resourceType, $providerId, ?array $notification): JSONResponse
	{
		// check if all required parameters are set
		if (
			$notificationType === null ||
			$resourceType === null ||
			$providerId === null ||
			!is_array($notification)
		) {
			return new JSONResponse(
				[
					'message' => 'Missing arguments',
					'validationErrors' => [],
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider($resourceType);
			$result = $provider->notificationReceived($notificationType, $providerId, $notification);
		} catch (ProviderDoesNotExistsException $e) {
			return new JSONResponse(
				[
					'message' => $e->getMessage(),
					'validationErrors' => [],
				],
				Http::STATUS_BAD_REQUEST
			);
		} catch (ShareNotFound $e) {
			$response = new JSONResponse(
				[
					'message' => $e->getMessage(),
					'validationErrors' => [],
				],
				Http::STATUS_BAD_REQUEST
			);
			$response->throttle();
			return $response;
		} catch (ActionNotSupportedException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_NOT_IMPLEMENTED
			);
		} catch (BadRequestException $e) {
			return new JSONResponse($e->getReturnMessage(), Http::STATUS_BAD_REQUEST);
		} catch (AuthenticationFailedException $e) {
			$response = new JSONResponse(['message' => 'RESOURCE_NOT_FOUND'], Http::STATUS_FORBIDDEN);
			$response->throttle();
			return $response;
		} catch (\Exception $e) {
			return new JSONResponse(
				[
					'message' => 'Internal error at ' . $this->urlGenerator->getBaseUrl(),
					'validationErrors' => [],
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}

	/**
	 * map login name to internal LDAP UID if a LDAP backend is in use
	 *
	 * @param string $uid
	 * @return string mixed
	 */
	private function mapUid($uid): string
	{
		// FIXME this should be a method in the user management instead
		$this->logger->debug('shareWith before, ' . $uid, ['app' => $this->appName]);
		\OCP\Util::emitHook(
			'\OCA\Files_Sharing\API\Server2Server',
			'preLoginNameUsedAsUserName',
			['uid' => &$uid]
		);
		$this->logger->debug('shareWith after, ' . $uid, ['app' => $this->appName]);

		return $uid;
	}

	/**
	 * Normalize protocol to the new format
	 * this way we can speak OCM 1.1.0 even with
	 * older implementations
	 *
	 * @param array $protocol
	 * @return array
	 */
	private function normalizeProtocol($protocol): array
	{
		if (array_key_exists('name', $protocol)) {
			return ['singleProtocolLegacy' => $protocol];
		}

		return $protocol;
	}

	/**
	 * Validate the protocol
	 *  For 1.0.0 this was:
	 *  !is_array($protocol) ||
	 *  !isset($protocol['name']) ||
	 *  !isset($protocol['options']) ||
	 *  !is_array($protocol['options']) ||
	 *  !isset($protocol['options']['sharedSecret'])
	 *
	 *  Now we chek all the things:
	 *  https://cs3org.github.io/OCM-API/docs.html?branch=v1.1.0&repo=OCM-API&user=cs3org#/paths/~1shares/post
	 * @param array $protocol
	 * @return bool
	 */
	private function validateProtocol($protocol): bool
	{
		if (!is_array($protocol)) {
			return false;
		}

		if (array_key_exists('singleProtocolLegacy', $protocol)) {
			$name = $protocol['singleProtocolLegacy']['name'];
			if (!isset($name) || $name !== 'webdav') {
				return false;
			}
			$options = $protocol['singleProtocolLegacy']['options'];
			if (!isset($options) || !is_array($options) || !isset($options['sharedSecret'])) {
				return false;
			}
			return true;
		}
		if (array_key_exists('singleProtocolNew', $protocol)) {
			$name = $protocol['singleProtocolNew']['name'];
			if (!isset($name) || $name !== 'webdav') {
				return false;
			}
			$options = $protocol['singleProtocolNew']['options'];
			if (!isset($options) || !is_array($options) || !isset($options['sharedSecret'])) {
				return false;
			}
			$webdav = $protocol['singleProtocolNew']['webdav'];
			if (
				!isset($webdav) ||
				!is_array($webdav) ||
				!isset($webdav['sharedSecret']) ||
				!isset($webdav['permissions']) ||
				!isset($webdav['uri'])
			) {
				return false;
			}
			return true;
		}
		if (array_key_exists('multipleProtocols', $protocol)) {
			$name = $protocol['multipleProtocols']['name'];
			if (!isset($name) || $name !== 'multi') {
				return false;
			}
			$webdav = $protocol['multipleProtocols']['webdav'];
			$webapp = $protocol['multipleProtocols']['webapp'];
			$datatx = $protocol['multipleProtocols']['datatx'];
			if (
				!isset($webdav) ||
				!isset($webapp) ||
				!isset($datatx)
			) {
				return false;
			}
			if (isset($webdav)) {
				if (!array_key_exists('uri', $webdav) || !array_key_exists('permissions', $webdav)) {
					return false;
				}
			}
			if (isset($webapp)) {
				if (!array_key_exists('uriTemplate', $webapp) || !array_key_exists('viewMode', $webapp)) {
					return false;
				}
			}
			if (isset($datatx)) {
				if (!array_key_exists('srcUri', $datatx) || !array_key_exists('size', $datatx)) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Extracts the shared secret from the protocol array.
	 * @param array $protocol
	 * @return string
	 */
	private function extractSharedSecret(array $protocol): string
	{
		$sharedSecret = '';
		if (array_key_exists('singleProtocolLegacy', $protocol)) {
			$sharedSecret = $protocol['singleProtocolLegacy']['options']['sharedSecret'];
		} elseif (array_key_exists('singleProtocolNew', $protocol)) {
			$sharedSecret = $protocol['singleProtocolNew']['options']['sharedSecret'];
		} elseif (array_key_exists('multipleProtocols', $protocol)) {
			$options = $protocol['multipleProtocols']['options'];
			if (isset($options)) {
				$sharedSecret = $options['sharedSecret'];
			}
		}
		return $sharedSecret;
	}
}
