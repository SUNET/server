<?php
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CloudFederationAPI\Controller;

use OCA\CloudFederationAPI\Config;
use OCA\CloudFederationAPI\ResponseDefinitions;
use OCA\Federation\TrustedServers;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Federation\Exceptions\ActionNotSupportedException;
use OCP\Federation\Exceptions\AuthenticationFailedException;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
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
class RequestHandlerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private LoggerInterface $logger,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
		private ICloudFederationProviderManager $cloudFederationProviderManager,
		private Config $config,
		private IConfig $ocConfig,
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
	 * @param string|null $sharedBy Provider specific UID of the user who shared the resource
	 * @param string|null $sharedByDisplayName Display name of the user who shared the resource
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
	public function addShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sharedBy, $sharedByDisplayName, $protocol, $shareType, $resourceType) {
		// check if all required parameters are set
		if ($shareWith === null ||
			$name === null ||
			$providerId === null ||
			$owner === null ||
			$resourceType === null ||
			$shareType === null ||
			!is_array($protocol) ||
			!isset($protocol['name']) ||
			!isset($protocol['options']) ||
			!is_array($protocol['options']) ||
			!isset($protocol['options']['sharedSecret'])
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

		$cloudId = $this->cloudIdManager->resolveCloudId($shareWith);
		$shareWith = $cloudId->getUser();

		if ($shareType === 'user') {
			$shareWith = $this->mapUid($shareWith);

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
		$sharedByDisplayName = $sharedByDisplayName === null ? $sharedBy : $sharedByDisplayName;

		// sharedBy* parameter is optional, if nothing is set we assume that it is the same user as the owner
		if ($sharedBy === null) {
			$sharedBy = $owner;
			$sharedByDisplayName = $ownerDisplayName;
		}

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider($resourceType);
			$share = $this->factory->getCloudFederationShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sharedBy, $sharedByDisplayName, '', $shareType, $resourceType);
			$share->setProtocol($protocol);
			$provider->shareReceived($share);
		} catch (ProviderDoesNotExistsException|ProviderCouldNotAddShareException $e) {
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
			Http::STATUS_CREATED);
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
	public function inviteAccepted(string $recipientProvider, string $token, string $userId, string $email, string $name): JSONResponse {
		$this->logger->debug('Invite accepted for ' . $userId . ' with token ' . $token . ' and email ' . $email . ' and name ' . $name);
		$found_for_this_user = false;
		foreach ($this->ocConfig->getAppKeys($this->appName) as $key) {
			if(str_starts_with($key, $token) ){
				$found_for_this_user = $this->getAppValue($this->appName, $token . '_remote_id') === $userId;
			}
		}



		if (!$found_for_this_user) {
			$response = ['message' => 'Invalid or non existing token', 'error' => true];
			$status = Http::STATUS_BAD_REQUEST;
			return new JSONResponse($response,$status);
		}

		if(!$this->trustedServers->isTrustedServer($recipientProvider)) {
			$response = ['message' => 'Remote server not trusted', 'error' => true];
			$status = Http::STATUS_FORBIDDEN;
			return new JSONResponse($response,$status);
		}
		// Note: Not implementing 404 Invitation token does not exist, instead using 400

		if ($this->ocConfig->getAppValue($this->appName, $token . '_accepted') === true ) {
			$response = ['message' => 'Invite already accepted', 'error' => true];
			$status = Http::STATUS_CONFLICT;
			return new JSONResponse($response,$status);
		}


		$localId = $this->ocConfig->getAppValue($this->appName, $token . '_local_id');
		$response = ['usedID' => $localId, 'email' => $email, 'name' => $name];
		$status = Http::STATUS_OK;
		$this->ocConfig->setAppValue($this->appName, $token . '_accepted', true);
		//  TODO: Set these values where the invitation workflow is implemented
		//  $this->ocConfig->setAppValue($this->appName, $token . '_accepted', false);
		//	$this->ocConfig->setAppValue($this->appName, $token . '_local_id', $localId);
		//	$this->ocConfig->setAppValue($this->appName, $token . '_remote_id', $remoteId);

		return new JSONResponse($response,$status);
	}

	/**
	 * Send a notification about an existing share
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
	public function receiveNotification($notificationType, $resourceType, $providerId, ?array $notification) {
		// check if all required parameters are set
		if ($notificationType === null ||
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
	private function mapUid($uid) {
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
}
