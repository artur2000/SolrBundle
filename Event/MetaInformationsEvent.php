<?php
namespace FS\SolrBundle\Event;

use FS\SolrBundle\Doctrine\Mapper\MetaInformationInterface;
use Solarium\Client;
use Solarium\QueryType\Select\Result\Document;
use Symfony\Component\EventDispatcher\Event as BaseEvent;

class MetaInformationsEvent extends BaseEvent
{

    /**
     * @var MetaInformationInterface
     */
    private $metainformation = null;

    /**
     * @var Document
     */
    private $document = null;

    /**
     * @param Client                   $client
     * @param MetaInformationInterface $metainformation
     * @param string                   $solrAction
     * @param Event                    $sourceEvent
     */
    public function __construct(
        MetaInformationInterface $metainformation = null
    )
    {
        $this->metainformation = $metainformation;
    }

    /**
     * @return MetaInformationInterface
     */
    public function getMetaInformation()
    {
        return $this->metainformation;
    }

}
