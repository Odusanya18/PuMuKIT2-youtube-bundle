<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeImportVideoCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $seriesRepo = null;
    private $youtubeRepo = null;

    private $youtubeService;
    private $factoryService;
    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:import:video')
            ->setDescription('Create a multimedia object from Youtube')
            ->addArgument('yid', InputArgument::REQUIRED, 'YouTube ID')
            ->addArgument('series', InputArgument::OPTIONAL, 'Series id where the object is created')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Status of the new multimedia object (published, blocked or hidden)', 'published')
            ->addOption('step', 'S', InputOption::VALUE_REQUIRED, 'Step of the importation. See help for more info', -99)
            ->addOption('tags', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Youtube tags to add in the object', array())
            ->setHelp(<<<EOT
Command to create a multimedia object from Youtube.

Steps:
 * 1.- Create the Multimedia Object (add tagging).

   Examples:
     <info>php bin/console youtube:import:video --env=prod --step=1 6aeJ7kOVfH8  58066eadd4c38ebf300041aa</info>
     <info>php bin/console youtube:import:video --env=prod --step=1 6aeJ7kOVfH8  PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 --tags=PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 --tags=PLW9tHnDKi2SZcLbuDgLYhHodMw8UH2fHN --status=bloq</info>

 * 2.- Download the image
 * 3.- Download/move the tracks
 * 4.- Tag object

   Examples:
     <info>php bin/console youtube:import:video --env=prod --step=4 6aeJ7kOVfH8  --tags=PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 --tags=PLW9tHnDKi2SZcLbuDgLYhHodMw8UH2fHN --status=bloq</info>


EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $yid = $input->getArgument('yid');
        $step = $input->getOption('step');
        switch ($step) {
        case 1:
            //Check if exists
            $status = $this->getStatus($input->getOption('status'));

            if ($this->getMmObjFromYid($yid)) {
                $output->writeln('<error>Already exists a mmobj from Youtube video with id ' . $yid .'</error>');
                return false;
            }

            $series = $this->getSeries($input->getArgument('series'));
            $output->writeln(sprintf(' * Creating multimedia object from id %s in series %s', $yid, $series->getId()));
            $mmobj = $this->createMultimediaObject($series, $yid, $status, $output);

            if($tags = $input->getOption('tags')) {
                $output->writeln(' * Tagging multimedia object ');
                $this->tagMultimediaObject($mmobj, $tags);
            }
            break;
        case 2:
            $output->writeln(' * TODO ');
            break;
        case 3:
            $output->writeln(' * TODO ');
            break;
        case 4:
            $mmobj = $this->getMmObjFromYid($yid);
            if (!$mmobj) {
                $output->writeln('<error>No mmobj from Youtube video with id ' . $yid .'</error>');
                return false;
            }
            $output->writeln(' * Tagging multimedia object ');
            $this->tagMultimediaObject($mmobj, $input->getOption('tags'));
            break;
        default:
            $output->writeln('<error>Select a valid step</error>');
        }
    }


    private function tagMultimediaObject(MultimediaObject $mmobj, $tagIds)
    {
        $tags = $this->tagRepo->findBy(array('properties.origin' => 'youtube', 'properties.youtube' => array('$in' => $tagIds)));
        if (count($tagIds) != count($tags)) {
            throw new \Exception(
                sprintf(
                    'No all tags found with this Youtube ids, input has %d id(s) and only %d tag(s) found',
                    count($tagIds),
                    count($tags)
                )
            );
        }


        foreach ($tags as $tag) {
            $this->tagService->addTag($mmobj, $tag);
        }

    }

    private function createMultimediaObject(Series $series, $yid, $status, OutputInterface $output)
    {
        try {
            $meta = $this->youtubeService->getVideoMeta($yid);
        } catch (\Exception $e) {
            $output->writeln('<error>No Youtube video with id ' . $yid .'</error>');
            return false;
        }

        //Create using the factory
        $mmobj = $this->factoryService->createMultimediaObject($series, false);
        $mmobj->setStatus($status);
        $mmobj->setTitle($meta['out']['snippet']['title']);
        if (isset($meta['out']['snippet']['description'])) {
            $mmobj->setDescription($meta['out']['snippet']['description']);
        }
        if (isset($meta['out']['snippet']['tags'])) {
            $mmobj->setKeywords($meta['out']['snippet']['tags']);
        }
        $dataTime = \DateTime::createFromFormat('Y-m-d\TH:i:s', substr($meta['out']['snippet']['publishedAt'], 0, 19));
        $mmobj->setRecordDate($dataTime);
        $mmobj->setPublicDate($dataTime);
        $mmobj->setProperty('origin', 'youtube');
        $mmobj->setProperty('youtubemeta', $meta['out']);

        $this->dm->persist($mmobj);
        $this->dm->flush();

        return $mmobj;
    }



    private function getMmObjFromYid($yid)
    {
        $mmobj = $this->mmobjRepo->findOneBy(array('properties.youtubemeta.id' => $yid));
        if ($mmobj) {
            return $mmobj;
        }


        $yt = $this->youtubeRepo
            ->createQueryBuilder()
            ->field('youtubeId')->equals($yid)
            ->getQuery()
            ->getSingleResult();

        if (!$yt) {
            return null;
        }

        return $this->mmobjRepo->find($yt->getMultimediaObjectId());
    }


    private function getSeries($seriesId)
    {
        if (!$seriesId) {
            throw new \Exception('No series id argument');
        }

        $series = $this->seriesRepo->find($seriesId);
        if ($series) {
            $this->logger->info(sprintf("Using series with id %s", $seriesId));
            return $series;
        }


        $series = $this->seriesRepo->findOneBy(array('properties.origin' => 'youtube', 'properties.fromyoutubetag' => $seriesId));
        if ($series) {
            $this->logger->info(sprintf("Using series with YouTube property %s", $seriesId));
            return $series;
        }

        //tag with youtube
        $tag = $this->tagRepo->findOneBy(array('properties.origin' => 'youtube', 'properties.youtube' => $seriesId));
        if ($tag) {
            $this->logger->info(sprintf("Creating series from YouTube property %s", $seriesId));
            $series = $this->factoryService->createSeries();
            $series->setI18nTitle($tag->getI18nTitle());
            $series->setProperty('origin', 'youtube');
            $series->setProperty('fromyoutubetag', $seriesId);

            $this->dm->persist($series);
            $this->dm->flush();

            return $series;

        }

        throw new \Exception('No series, or YouTube tag with id '. $seriesId);
    }


    private function getStatus($status)
    {
        $status = strtolower($status);
        $validStatus = array('published', 'pub', 'block', 'blocked', 'hide', 'hidden');
        if (!in_array($status, $validStatus)) {
            throw new \Exception('Status "' . $status . '" not in '. implode(', ', $validStatus));
        }

        switch ($status) {
          case 'published':
          case 'pub':
              return MultimediaObject::STATUS_PUBLISHED;
          case 'block':
          case 'blocked':
              return MultimediaObject::STATUS_BLOCKED;
          case 'hide':
          case 'hidden':
              return MultimediaObject::STATUS_HIDDEN;
        }
        return MultimediaObject::STATUS_PUBLISHED;
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->seriesRepo = $this->dm->getRepository('PumukitSchemaBundle:Series');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->factoryService = $this->getContainer()->get('pumukitschema.factory');
        $this->tagService = $this->getContainer()->get('pumukitschema.tag');

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }
}