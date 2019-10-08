<?php

namespace FS\SolrBundle\Command;

use FS\SolrBundle\SolrException;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command clears the whole index
 */
class ClearIndexCommand implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('solr:index:clear')
            ->setDescription('Clear the whole index');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $solr = $this->container->get('solr.client');

        try {
            $solr->clearIndex();
        } catch (SolrException $e) {
            $output->writeln(sprintf('A error occurs: %s', $e->getMessage()));
        }

        $output->writeln('<info>Index successful cleared.</info>');
    }
}
