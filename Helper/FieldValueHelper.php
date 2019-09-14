<?php
/**
 * Created by IntelliJ IDEA.
 * User: artur
 * Date: 09.06.19
 * Time: 12:46
 */

namespace FS\SolrBundle\Helper;


use Doctrine\ORM\EntityManagerInterface;

interface FieldValueHelper
{

    public function fetch(Object $entity, $fieldValue=null, ?array $parameters);

}