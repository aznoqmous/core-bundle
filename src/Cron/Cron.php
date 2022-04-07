<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Cron;

use Contao\CoreBundle\Entity\CronJob as CronJobEntity;
use Contao\CoreBundle\Repository\CronJobRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class Cron
{
    final public const SCOPE_WEB = 'web';
    final public const SCOPE_CLI = 'cli';

    /**
     * @var array<CronJob>
     */
    private array $cronJobs = [];

    /**
     * @param \Closure():CronJobRepository      $repository
     * @param \Closure():EntityManagerInterface $entityManager
     */
    public function __construct(private \Closure $repository, private \Closure $entityManager, private ?LoggerInterface $logger = null)
    {
    }

    public function addCronJob(CronJob $cronjob): void
    {
        $this->cronJobs[] = $cronjob;
    }

    /**
     * Run all the registered Contao cron jobs.
     */
    public function run(string $scope): void
    {
        // Validate scope
        if (self::SCOPE_WEB !== $scope && self::SCOPE_CLI !== $scope) {
            throw new \InvalidArgumentException('Invalid scope "'.$scope.'"');
        }

        /** @var CronJobRepository $repository */
        $repository = ($this->repository)();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = ($this->entityManager)();

        /** @var array<CronJob> $cronJobsToBeRun */
        $cronJobsToBeRun = [];

        $now = new \DateTimeImmutable();

        try {
            // Lock cron table
            $repository->lockTable();

            // Go through each cron job
            foreach ($this->cronJobs as $cron) {
                $interval = $cron->getInterval();
                $name = $cron->getName();

                // Determine the last run date
                $lastRunDate = null;

                /** @var CronJobEntity|null $lastRunEntity */
                $lastRunEntity = $repository->findOneByName($name);

                if (null !== $lastRunEntity) {
                    $lastRunDate = $lastRunEntity->getLastRun();
                } else {
                    $lastRunEntity = new CronJobEntity($name);
                    $entityManager->persist($lastRunEntity);
                }

                // Check if the cron should be run
                $expression = CronExpression::factory($interval);

                if (null !== $lastRunDate && $now < $expression->getNextRunDate($lastRunDate)) {
                    continue;
                }

                // Update the cron entry
                $lastRunEntity->setLastRun($now);

                // Add job to the crons to be run
                $cronJobsToBeRun[] = $cron;
            }

            $entityManager->flush();
        } finally {
            $repository->unlockTable();
        }

        // Execute all crons to be run
        foreach ($cronJobsToBeRun as $cron) {
            $this->logger?->debug(sprintf('Executing cron job "%s"', $cron->getName()));

            $cron($scope);
        }
    }
}
