<?php
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCP\Federation;

/**
 * Interface ICloudFederationFactory
 *
 *
 * @since 14.0.0
 */
interface ICloudFederationFactory {
	/**
	 * get a CloudFederationShare Object to prepare a share you want to send
	 *
	 * @param string $shareWith
	 * @param string $name resource name (e.g. document.odt)
	 * @param string $description share description (optional)
	 * @param string $providerId resource UID on the provider side
	 * @param string $owner provider specific UID of the user who owns the resource
	 * @param string $ownerDisplayName display name of the user who shared the item
	 * @param string $sharedBy provider specific UID of the user who shared the resource
	 * @param string $sharedByDisplayName display name of the user who shared the resource
	 * @param string $sharedSecret used to authenticate requests across servers
	 * @param string $shareType ('group' or 'user' share)
	 * @param $resourceType ('file', 'calendar',...)
	 * @return ICloudFederationShare
	 * @deprecated 30.0.0 use ICloudFederationShareManager::createCloudFederationShare() instead
	 *
	 * @since 14.0.0
	 */
	public function getCloudFederationShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sharedBy, $sharedByDisplayName, $sharedSecret, $shareType, $resourceType);

	/**
	 * get a CloudFederationShare Object to prepare a share you want to send
	 *
	 * @param string $shareWith
	 * @param string $name resource name (e.g. document.odt)
	 * @param string $description share description (optional)
	 * @param string $providerId resource UID on the provider side
	 * @param string $owner provider specific UID of the user who owns the resource
	 * @param string $ownerDisplayName display name of the user who shared the item
	 * @param string $senderDisplayName display name of the user who shared the resource
	 * @param string $sharedSecret used to authenticate requests across servers
	 * @param string $shareType ('group' or 'user' share)
	 * @param string $resourceType ('file', 'calendar',...)
	 * @param int    $expiration (optional)
	 * @param array  $protocol (optional)
	 * @return ICloudFederationShare
	 *
	 * @since 30.0.0
	 */
	public function createCloudFederationShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sharedBy, $sharedByDisplayName, $sharedSecret, $shareType, $resourceType, $expiration, $protocol);
	/**
	 * get a Cloud FederationNotification object to prepare a notification you
	 * want to send
	 *
	 * @return ICloudFederationNotification
	 *
	 * @since 14.0.0
	 */
	public function getCloudFederationNotification();
}
