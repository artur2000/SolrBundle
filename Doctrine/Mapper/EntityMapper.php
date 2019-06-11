<?php
namespace FS\SolrBundle\Doctrine\Mapper;

use FS\SolrBundle\Doctrine\Hydration\HydrationModes;
use FS\SolrBundle\Doctrine\Hydration\HydratorInterface;
use FS\SolrBundle\Doctrine\Mapper\Factory\DocumentFactory;
use FS\SolrBundle\Doctrine\Mapper\Mapping\AbstractDocumentCommand;
use FS\SolrBundle\Doctrine\Annotation\Index as Solr;
use Psr\Log\LoggerInterface;
use Solarium\QueryType\Update\Query\Document\Document;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Container;

class EntityMapper implements EntityMapperInterface
{
    /**
     * @var HydratorInterface
     */
    private $doctrineHydrator;

    /**
     * @var HydratorInterface
     */
    private $indexHydrator;

    /**
     * @var string
     */
    private $hydrationMode = '';

    /**
     * @var MetaInformationFactory
     */
    private $metaInformationFactory;

    /**
     * @var DocumentFactory
     */
    private $documentFactory;

    /** @var EventDispatcherInterface  */
    private $dispatcher;

    /**
     * @param HydratorInterface $doctrineHydrator
     * @param HydratorInterface $indexHydrator
     * @param MetaInformationFactory $metaInformationFactory
     * @param EventDispatcherInterface $dispatcher
     * @param LoggerInterface $logger
     * @param Container $container
     * @throws \Exception
     */
    public function __construct(
        HydratorInterface $doctrineHydrator,
        HydratorInterface $indexHydrator,
        MetaInformationFactory $metaInformationFactory,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        Container $container
    )
    {
        $this->doctrineHydrator = $doctrineHydrator;
        $this->indexHydrator = $indexHydrator;
        $this->metaInformationFactory = $metaInformationFactory;
        $this->dispatcher = $dispatcher;
        $this->documentFactory = new DocumentFactory($metaInformationFactory, $this->dispatcher, $container, $logger);
        $this->hydrationMode = HydrationModes::HYDRATE_DOCTRINE;
    }

    /**
     * {@inheritdoc}
     */
    public function toDocument(MetaInformationInterface $metaInformation)
    {
        return $this->documentFactory->createDocument($metaInformation);
    }

    /**
     * {@inheritdoc}
     */
    public function toEntity(\ArrayAccess $document, $sourceTargetEntity)
    {
        if (null === $sourceTargetEntity) {
            throw new SolrMappingException('$sourceTargetEntity should not be null');
        }

        $metaInformation = $this->metaInformationFactory->loadInformation($sourceTargetEntity);

        if ($metaInformation->isDoctrineEntity() === false && $this->hydrationMode == HydrationModes::HYDRATE_DOCTRINE) {
            throw new SolrMappingException(sprintf('Please check your config. Given entity is not a Doctrine entity, but Doctrine hydration is enabled. Use setHydrationMode(HydrationModes::HYDRATE_DOCTRINE) to fix this.'));
        }

        if ($this->hydrationMode == HydrationModes::HYDRATE_INDEX) {
            return $this->indexHydrator->hydrate($document, $metaInformation);
        }

        if ($this->hydrationMode == HydrationModes::HYDRATE_DOCTRINE) {
            return $this->doctrineHydrator->hydrate($document, $metaInformation);
        }
    }

    /**
     * @param string $mode
     */
    public function setHydrationMode($mode)
    {
        $this->hydrationMode = $mode;
    }
}
