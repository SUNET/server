<?php

/**
 * SPDX-FileCopyrightText: 2017-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\CloudFederationAPI\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\Job;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;


class SendShareJob extends Job
{
	private bool $retainJob = true;

	/** @var int max number of attempts to send the request */
	private int $maxTry = 20;

	/** @var int how much time should be between two tries (10 minutes) */
	private int $interval = 600;
	public function __construct(
		private IQueryBuilder $queryBuilder,
		private ICloudFederationProviderManager $cloudFederationProviderManager,
		private ICloudFederationShare $cloudFederationShare,
		ITimeFactory $time
	) {
		parent::__construct($time);
	}

	/**
	 * Run the job, then remove it from the jobList
	 */
	public function start(IJobList $jobList): void
	{
		if ($this->shouldRun($this->argument)) {
			parent::start($jobList);
			$jobList->remove($this, $this->argument);
			if ($this->retainJob) {
				$this->reAddJob($jobList, $this->argument);
			}
		}
	}

	protected function run($argument)
	{
		$description = $argument['description'];
		$name = $argument['name'];
		$owner = $argument['owner'];
		$ownerDisplayName = $argument['ownerDisplayName'];
		$permissions = $argument['permissions'];
		$recipientProvider = $argument['recipientProvider'];
		$resourceType = $argument['resourceType'];
		$sender = $argument['sender'];
		$senderDisplayName = $argument['senderDisplayender'];
		$shareType = $argument['shareType'];
		$sharedSecret = $argument['sharedSecret'];
		$receiver = $argument['recipient'];
		$uri = $argument['uri'];
		$protocol = [
			'name' => 'webdav',
			'options' => [
				'sharedSecret' => $sharedSecret,
			],
			'webdav' => [
				'sharedSecret' => $sharedSecret,
				'permissions' => $permissions,
				'uri' => $uri

			]
		];

		$this->cloudFederationShare->setDescription($description);
		$this->cloudFederationShare->setOwner($owner);
		$this->cloudFederationShare->setOwnerDisplayName($ownerDisplayName);
		$this->cloudFederationShare->setProtocol($protocol);
		$this->cloudFederationShare->setProviderId($recipientProvider);
		$this->cloudFederationShare->setResourceName($name);
		$this->cloudFederationShare->setResourceType($resourceType);
		$this->cloudFederationShare->setSender($sender);
		$this->cloudFederationShare->setSenderDisplayName($senderDisplayName);
		$this->cloudFederationShare->setShareType($shareType);
		$this->cloudFederationShare->setShareWith($receiver);

		$reply = $this->cloudFederationProviderManager->sendCloudShare($this->cloudFederationShare);

		$result = $reply->getStatus() === 201;
		$try = (int)$argument['try'] + 1;
		if ($result === true || $try > $this->maxTry) {
			$this->retainJob = false;
		}
	}
	/**
	 * Re-add background job with new arguments
	 */
	protected function reAddJob(IJobList $jobList, array $argument): void
	{
		$jobList->add(
			SendShareJob::class,
			[
				'description' => $argument['description'],
				'name' => $argument['name'],
				'owner' => $argument['owner'],
				'ownerDisplayName' => $argument['ownerDisplayName'],
				'permissions' => $argument['permissions'],
				'recipientProvider' => $argument['recipientProvider'],
				'resourceType' => $argument['resourceType'],
				'sender' => $argument['sender'],
				'senderDisplayName' => $argument['senderDisplayender'],
				'shareType' => $argument['shareType'],
				'sharedSecret' => $argument['sharedSecret'],
				'receiver' => $argument['recipient'],
				'uri' => $argument['uri'],
				'id' => $argument['id'],
				'lastRun' => $this->time->getTime(),
				'try' => (int)$argument['try'] + 1,
			]
		);
	}

	/**
	 * Test if it is time for the next run
	 */
	protected function shouldRun(array $argument): bool
	{
		$lastRun = (int)$argument['lastRun'];
		return (($this->time->getTime() - $lastRun) > $this->interval);
	}
}
		// $this->queryBuilder->select('id', 'sender', 'email', 'name', 'status')
		// 	->from('federated_invites')
		// 	->where('token', $this->queryBuilder->expr()->eq($token, $this->queryBuilder->createNamedParameter($token)))
		// 	->andWhere('userId', $this->queryBuilder->expr()->eq($userId, $this->queryBuilder->createNamedParameter($userId)))
		// 	->andWhere('recipientProvider', $this->queryBuilder->expr()->eq($recipientProvider, $this->queryBuilder->createNamedParameter($recipientProvider)))
