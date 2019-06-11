<?php

namespace FS\SolrBundle\Doctrine\Mapper\Factory;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use FS\SolrBundle\Doctrine\Annotation\Field;
use FS\SolrBundle\Doctrine\Mapper\MetaInformationFactory;
use FS\SolrBundle\Doctrine\Mapper\MetaInformationInterface;
use FS\SolrBundle\Doctrine\Mapper\SolrMappingException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Solarium\QueryType\Update\Query\Document\Document;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use FS\SolrBundle\Event\MetaInformationsEvent;
use FS\SolrBundle\Event\DocumentEvent;
use FS\SolrBundle\Event\Events;
use Symfony\Component\DependencyInjection\Container;

class DocumentFactory
{
    /**
     * @var MetaInformationFactory
     */
    private $metaInformationFactory;

    /** @var EventDispatcherInterface  */
    private $dispatcher;

    /** @var EntityManagerInterface  */
    private $entityManager;

    /** @var Container  */
    private $serviceContainer;

    /** @var LoggerInterface  */
    private $logger;

    /**
     * @param MetaInformationFactory $metaInformationFactory
     * @param EventDispatcherInterface $dispatcher
     * @param Container $container
     * @throws \Exception
     */
    public function __construct(
        MetaInformationFactory $metaInformationFactory,
        EventDispatcherInterface $dispatcher,
        Container $container,
        LoggerInterface $logger
    )
    {
        $this->metaInformationFactory = $metaInformationFactory;
        $this->dispatcher = $dispatcher;
        $this->serviceContainer = $container;
        $this->logger = $logger;
    }

    private function createDocumentProcessField(Field $field, Document $document, $entity) {
        $fieldValue = $field->getValue();
        if ($field->helper) { // call helper method to fetch external data
            $getterValue = $this->callHelperMethod($entity, $field->getHelperName(), $fieldValue);
            $document->addField($field->getNameWithAlias(), $getterValue, $field->getBoost());
        } else if (($fieldValue instanceof Collection || is_array($fieldValue)) && $field->nestedClass) {
            $val = $this->mapCollectionField($document, $field, $entity);
            $val = $val;
        } else if (($fieldValue instanceof Collection || is_array($fieldValue)) && !$field->nestedClass) {
            // traverse the collection and fetch the values using the gater on the collection elements if objects
            $this->mapCollectionFieldSimple($document, $field);
        } else if (is_object($fieldValue) && $field->nestedClass) { // index sinsgle object as nested child-document
            $document->addField('_childDocuments_', [$this->objectToDocument($fieldValue)], $field->getBoost());
        } else if (is_object($fieldValue) && !$field->nestedClass) { // index object as "flat" string, call getter
            $document->addField($field->getNameWithAlias(), $this->mapObjectField($field), $field->getBoost());
        } else if ($field->getter && $fieldValue) { // call getter to transform data (json to array, etc.)
            $getterValue = $this->callGetterMethod($entity, $field->getGetterName());
            $document->addField($field->getNameWithAlias(), $getterValue, $field->getBoost());
        } else { // field contains simple data-type
            $document->addField($field->getNameWithAlias(), $fieldValue, $field->getBoost());
        }
    }

    /**
     * @param MetaInformationInterface $metaInformation
     *
     * @return null|Document
     *
     * @throws SolrMappingException if no id is set
     */
    public function createDocument(MetaInformationInterface $metaInformation)
    {

        if (!$metaInformation->getEntityId() && !$metaInformation->generateDocumentId()) {
            throw new SolrMappingException(sprintf('No entity id set for "%s"', $metaInformation->getClassName()));
        }

        $documentId = $metaInformation->getDocumentKey();
        if ($metaInformation->generateDocumentId()) {
            $documentId = $metaInformation->getDocumentName() . '_' . Uuid::uuid1()->toString();
        }

        $document = new Document();
        $document->setKey(MetaInformationInterface::DOCUMENT_KEY_FIELD_NAME, $documentId);

        $document->setBoost($metaInformation->getBoost());

        $entity = $metaInformation->getEntity();
        $fields = $metaInformation->getFields();
        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                continue;
            }
            $this->createDocumentProcessField($field, $document, $metaInformation->getEntity());
            if ($field->getFieldModifier()) {
                $document->setFieldModifier($field->getNameWithAlias(), $field->getFieldModifier());
            }

        }

        return $document;
    }

    /**
     * @param Field $field
     *
     * @return array|string
     *
     * @throws SolrMappingException if getter return value is object
     */
    private function mapObjectField(Field $field)
    {
        $value = $field->getValue();
        $getter = $field->getGetterName();
        if (empty($getter)) {
            throw new SolrMappingException(sprintf('Please configure a getter for property "%s" in class "%s"', $field->name, get_class($value)));
        }

        $getterReturnValue = $this->callGetterMethod($value, $getter);

        if (is_object($getterReturnValue)) {
            throw new SolrMappingException(sprintf('The configured getter "%s" in "%s" must return a string or array, got object', $getter, get_class($value)));
        }

        return $getterReturnValue;
    }

    /**
     * @param object $object
     * @param string $getter
     *
     * @return mixed
     *
     * @throws SolrMappingException if given getter does not exists
     */
    private function callGetterMethod($object, $getter)
    {
        $methodName = $getter;
        if (strpos($getter, '(') !== false) {
            $methodName = substr($getter, 0, strpos($getter, '('));
        }

        if (!method_exists($object, $methodName)) {
            return null;
            //throw new SolrMappingException(sprintf('No method "%s()" found in class "%s"', $methodName, get_class($object)));
        }

        $method = new \ReflectionMethod($object, $methodName);
        // getter with arguments
        if (strpos($getter, ')') !== false) {
            $getterArguments = explode(',', substr($getter, strpos($getter, '(') + 1, -1));
            $getterArguments = array_map(function ($parameter) {
                return trim(preg_replace('#[\'"]#', '', $parameter));
            }, $getterArguments);

            return $method->invokeArgs($object, $getterArguments);
        }

        return $method->invoke($object);
    }

    /**
     * @param object $object
     * @param string $helper
     *
     * @return mixed
     *
     * @throws SolrMappingException if given helper does not exists
     */
    private function callHelperMethod($object, $helper, $fieldValue)
    {
        $methodName = $helper;

        try {
            if (strpos($helper, '::') !== false) {
                $className = substr($helper, 0, strpos($helper, '::'));
                $methodName = substr($helper, strpos($helper, '::') + 2);
                $helperObject = $this->serviceContainer->get($className);
            } else {
                $helperObject = null;
            }

            if (strpos($helper, '(') !== false) {
                $methodName = substr($methodName, 0, strpos($methodName, '('));
            }

            if ($helperObject && !method_exists($helperObject, $methodName)) {
                throw new SolrMappingException(sprintf('No method "%s()" found in class "%s"', $methodName, get_class($helperObject)));
            }

            $method = new \ReflectionMethod($helperObject, $methodName);

            // pass the entity itself as first argument
            $getterArguments = [$object];

            // pass the original value as second argument
            $getterArguments[] = $fieldValue;

            // getter with additional arguments in a third array argument
            if (strpos($helper, ')') !== false) {
                $additionalArguments = explode(',', substr($helper, strpos($helper, '(') + 1, -1));
                $additionalArguments = array_map(function ($parameter) {
                    return trim(preg_replace('#[\'"]#', '', $parameter));
                }, $additionalArguments);
                $getterArguments[] = $additionalArguments;
            }

            return $method->invokeArgs($helperObject, $getterArguments);
        } catch (\Exception $e) {
            // do not stop the process in case of failure
            $this->logger->error($e->getMessage(), $e->getTrace());
        }

    }

    /**
     * @param Field  $field
     * @param string $sourceTargetClass
     *
     * @return array
     *
     * @throws SolrMappingException if no getter method was found
     */
    private function mapCollectionField($document, Field $field, $sourceTargetObject)
    {
        /** @var Collection $collection */
        $collection = $field->getValue();
        $getter = $field->getGetterName();

        if ($getter != '') {
            $collection = $this->callGetterMethod($sourceTargetObject, $getter);

            $collection = array_filter($collection, function ($value) {
                return $value !== null;
            });
        }

        $values = [];
        if (count($collection)) {
            foreach ($collection as $relatedObj) {
                if (is_object($relatedObj)) {
                    $values[] = $this->objectToDocument($relatedObj);
                } else {
                    $values[] = $relatedObj;
                }
            }

            $document->addField('_childDocuments_', $values, $field->getBoost());
        }

        return $values;
    }

    /**
     * @param Field  $field
     * @param string $sourceTargetClass
     *
     * @return array
     *
     * @throws SolrMappingException if no getter method was found
     */
    private function mapCollectionFieldSimple($document, Field $field)
    {
        /** @var Collection $collection */
        $collection = $field->getValue();
        $getter = $field->getGetterName();

        if (is_array($collection)) {
            $collection = array_filter($collection, function ($value) {
                return $value !== null;
            });
        }

        $values = [];
        if (count($collection)) {
            foreach ($collection as $item) {
                if (is_object($item) && $getter != '') {
                    $values[] = $this->callGetterMethod($item, $getter);
                } else {
                    $values[] = $item;
                }
            }

        }

        $document->addField($field->getNameWithAlias(), $values);

        return $values;
    }

    /**
     * @param mixed $value
     *
     * @return array
     *
     * @throws SolrMappingException
     */
    private function objectToDocument($value)
    {
        $metaInformation = $this->metaInformationFactory->loadInformation($value);

        $field = [];

        $this->dispatcher->dispatch(Events::PRE_DOCUMENT_CREATE, new MetaInformationsEvent(
            $metaInformation
        ));

        $document = $this->createDocument($metaInformation);

        $this->dispatcher->dispatch(Events::POST_DOCUMENT_CREATE, new DocumentEvent(
            $metaInformation,
            $document
        ));

        foreach ($document as $fieldName => $value) {
            $field[$fieldName] = $value;
        }

        return $field;
    }
}