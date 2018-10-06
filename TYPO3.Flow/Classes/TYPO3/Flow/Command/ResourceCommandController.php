<?php
namespace TYPO3\Flow\Command;

/*                                                                        *
 * This script belongs to the Flow framework.                             *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the MIT license.                                          *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\Flow\Exception;
use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Flow\Package\PackageManagerInterface;
use TYPO3\Flow\Persistence\Doctrine\QueryResult;
use TYPO3\Flow\Persistence\PersistenceManagerInterface;
use TYPO3\Flow\Resource\Collection;
use TYPO3\Flow\Resource\CollectionInterface;
use TYPO3\Flow\Resource\ResourceManager;
use TYPO3\Flow\Resource\ResourceRepository;

/**
 * Resource command controller for the TYPO3.Flow package
 *
 * @Flow\Scope("singleton")
 */
class ResourceCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ResourceRepository
     */
    protected $resourceRepository;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var PackageManagerInterface
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Publish resources
     *
     * This command publishes the resources of the given or - if none was specified, all - resource collections
     * to their respective configured publishing targets.
     *
     * @param string $collection If specified, only resources of this collection are published. Example: 'persistent'
     * @param string $newerThan process only resources newer than the specified time stamp (Format must be parseable by new \DateTime)
     * @return void
     */
    public function publishCommand($collection = null, $newerThan = null)
    {
        try {

            $newerThan = $newerThan ? new \DateTime($newerThan) : null;

            if ($collection === null) {
                $collections = $this->resourceManager->getCollections();
            } else {
                $collections = array();
                $collections[$collection] = $this->resourceManager->getCollection($collection);
                if ($collections[$collection] === null) {
                    $this->outputLine('Collection "%s" does not exist.', array($collection));
                    $this->quit(1);
                }
            }

            foreach ($collections as $collection) {
                /** @var CollectionInterface $collection */
                $this->outputLine('Publishing resources of collection "%s"', array($collection->getName()));
                /** @var \TYPO3\Flow\Resource\Collection $collection */
                /** @var QueryResult $findResourcesResult */
                $findResourcesResult = $collection->findResources($newerThan);
                $this->output->progressStart($findResourcesResult->count());
                if ($collection->getTarget() instanceof \TYPO3\Flow\Resource\Target\FileSystemSymlinkTarget) {
                    $collection->publish(function() {
                        $this->output->progressAdvance();
                    }, $newerThan);
                } else {
                    $collection->publish();
                }
                $this->output->progressFinish();
            }
        } catch (Exception $exception) {
            $this->outputLine();
            $this->outputLine('An error occurred while publishing resources (see full description below). You can check and probably fix the integrity of the resource registry by using the resource:clean command.');
            $this->outputLine('%s (Exception code: %s)', array(get_class($exception), $exception->getCode()));
            $this->outputLine($exception->getMessage());
            $this->quit(1);
        }
    }

    /**
     * Clean up resource registry
     *
     * This command checks the resource registry (that is the database tables) for orphaned resource objects which don't
     * seem to have any corresponding data anymore (for example: the file in Data/Persistent/Resources has been deleted
     * without removing the related Resource object).
     *
     * If the TYPO3.Media package is active, this command will also detect any assets referring to broken resources
     * and will remove the respective Asset object from the database when the broken resource is removed.
     *
     * This command will ask you interactively what to do before deleting anything.
     *
     * @return void
     */
    public function cleanCommand()
    {
        $this->outputLine('Checking if resource data exists for all known resource objects ...');
        $this->outputLine();

        $mediaPackagePresent = $this->packageManager->isPackageActive('TYPO3.Media');

        $resourcesCount = $this->resourceRepository->countAll();
        $this->output->progressStart($resourcesCount);

        $brokenResources = array();
        $relatedAssets = new \SplObjectStorage();
        $query = $this->resourceRepository->createQuery();
        if ($query instanceof \TYPO3\Flow\Persistence\Doctrine\Query) {
            // if available, do use the Doctrine iterate API to save system resources and for better performance
            $dquery = $query->getQueryBuilder()->getQuery();
            $em = $dquery->getEntityManager();
            foreach ($dquery->iterate(null, \Doctrine\ORM\Query::HYDRATE_OBJECT) as $row) {
                $resource = $row[0];
                $this->output->progressAdvance(1);
                /* @var \TYPO3\Flow\Resource\Resource $resource */
                $stream = $resource->getStream();
                if (!is_resource($stream)) {
                    $brokenResources[] = $resource;
                } else {
                    // detach from Doctrine, so that it can be GC'd immediately
                    $em->detach($resource);
                }
            }
        } else {
            foreach ($this->resourceRepository->findAll() as $resource) {
                $this->output->progressAdvance(1);
                /* @var \TYPO3\Flow\Resource\Resource $resource */
                $stream = $resource->getStream();
                if (!is_resource($stream)) {
                    $brokenResources[] = $resource;
                }
            }
        }

        $this->output->progressFinish();
        $this->outputLine();

        if ($mediaPackagePresent && count($brokenResources) > 0) {
            $assetRepository = $this->objectManager->get('TYPO3\Media\Domain\Repository\AssetRepository');
            /* @var \TYPO3\Media\Domain\Repository\AssetRepository $assetRepository */

            foreach ($brokenResources as $resource) {
                $assets = $assetRepository->findByResource($resource);
                if ($assets !== null) {
                    $relatedAssets[$resource] = $assets;
                }
            }
        }

        if (count($brokenResources) > 0) {
            $this->outputLine('<b>Found %s broken resource(s):</b>', array(count($brokenResources)));
            $this->outputLine();

            foreach ($brokenResources as $resource) {
                $this->outputLine('%s (%s) %s', array($resource->getFilename(), $resource->getSha1(), $resource->getCollectionName()));
                if (isset($relatedAssets[$resource])) {
                    foreach ($relatedAssets[$resource] as $asset) {
                        $this->outputLine(' -> %s (%s)', array(get_class($asset), $asset->getIdentifier()));
                    }
                }
            }
            $response = null;
            while (!in_array($response, array('y', 'n', 'c'))) {
                $response = $this->output->ask('<comment>Do you want to remove all broken resource objects and related assets from the database? (y/n/c) </comment>');
            }

            switch ($response) {
                case 'y':
                    foreach ($brokenResources as $resource) {
                        $resource->disableLifecycleEvents();
                        $this->persistenceManager->remove($resource);
                        if (isset($relatedAssets[$resource])) {
                            foreach ($relatedAssets[$resource] as $asset) {
                                $assetRepository->remove($asset);
                            }
                        }
                    }
                    $this->outputLine('Removed %s resource object(s) from the database.', array(count($brokenResources)));
                break;
                case 'n':
                    $this->outputLine('Did not delete any resource objects.');
                break;
                case 'c':
                    $this->outputLine('Stopping. Did not delete any resource objects.');
                    $this->quit(0);
                break;
            }
        }
    }
}
