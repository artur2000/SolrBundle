<?php
namespace FS\SolrBundle\Event;

use FS\SolrBundle\Doctrine\Mapper\MetaInformationInterface;
use Solarium\Client;
use Solarium\QueryType\Update\Query\Document\Document;
use Symfony\Component\EventDispatcher\Event as BaseEvent;

class DocumentEvent extends BaseEvent
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
        MetaInformationInterface $metainformation = null,
        Document $document
    )
    {
        $this->metainformation = $metainformation;
        $this->document = $document;
    }

    /**
     * @return MetaInformationInterface
     */
    public function getMetaInformation()
    {
        return $this->metainformation;
    }

    /**
     * @return Event
     */
    public function getDocument()
    {
        return $this->document;
    }

}
