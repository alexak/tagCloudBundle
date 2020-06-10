<?php

namespace  Raumobil\HomeAndSmart\TagCloudBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Yaml\Yaml;
use Netgen\TagsBundle\API\Repository\TagsService;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;


/**
 * Class CreateDefaultTagsCommand
 *
 * füllt ein Contentobject vom Typ 'hs_tagcloud' mit den default Werten aus der yml Datei..  
 *
 * @package Raumobil\HomeAndSmart\TagCloudBundle\Command
 */
class CreateDefaultTagsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName( 'raumobil:hs:tag:createDefaultTags' )->setDefinition(
            array(
                new InputArgument( 'contentId', InputArgument::REQUIRED, 'id, die den Content identifiziert' ),
                new InputArgument( 'fieldIdentifier', InputArgument::REQUIRED, 'String, der das Field identifiziert' ),
                new InputArgument( 'override', InputArgument::OPTIONAL, 'vorhandene ersetzen' )
            )
        );
    }

    protected function execute( InputInterface $input, OutputInterface $output )
    {
        // get the default tags from yml..
        $mainKeyWords = Yaml::parse(file_get_contents(__DIR__.'/../../../HomeAndSmartBundle/Resources/config/tagList.yml'));
        $inputKeywords = array();

        foreach($mainKeyWords as $keywords){
            foreach ($keywords as $keyword => $value){
                if($value['isVisible'] && $value['isStatic']){
                    $inputKeywords[] = $keyword;
                }
            }
        }


        // get input parameters
        $inputContentId = $input->getArgument( "contentId");
        $inputFieldIdentifier = $input->getArgument( "fieldIdentifier");
        $override = $input->getArgument("override");
        $override = (!empty($override) && boolval($override));


        // connection to repository
        $output->writeln( "<info>connection to repository" );

        /** @var $repository \eZ\Publish\API\Repository\Repository */
        $repository = $this->getContainer()->get( 'ezpublish.api.repository' );
        $contentService = $repository->getContentService();
        $contentTypeService = $repository->getContentTypeService();

        //14 appears to be the default admin user id
        $repository->setCurrentUser( $repository->getUserService()->loadUser( 14 ) );

        // get the content
        $output->writeln("<info>suche Content mit id $inputContentId");

        try {
            $contentInfo = $contentService->loadContentInfo($inputContentId);

            $output->writeln("<info>Content gefunden: " . $contentInfo->name);
            $output->writeln("<info>suche Field mit type eztags (und identifier $inputFieldIdentifier)");
            $contentType = $contentTypeService->loadContentType($contentInfo->contentTypeId);
            $fieldDefinitions = $contentType->getFieldDefinitions();
            $fieldDefIdentifier = null;

            foreach ($fieldDefinitions as $fieldDefinition) {
                if ($fieldDefinition->fieldTypeIdentifier == "eztags" && (!$inputFieldIdentifier || $inputFieldIdentifier == $fieldDefinition->identifier)) {
                    $output->writeln("<info>Field gefunden: " . $fieldDefinition->fieldTypeIdentifier . " " . $fieldDefinition->identifier);
                    $fieldDefIdentifier = $fieldDefinition->identifier;
                    break;
                }
            }

            if (is_null($fieldDefIdentifier)) {
                $output->writeln("<error>Content hat kein eztags Field (bzw keines mit dem identifier $inputFieldIdentifier)");
                return;
            }

            $tagsService = $this->getContainer()->get("ezpublish.api.service.tags.inner");

            // get the Tags from current content
            $output->writeln("<info>lade vorhandene Tags von Content");
            $content = $contentService->loadContentByContentInfo($contentInfo);
            $tagsValue = $content->getFieldValue($fieldDefIdentifier);
            $tagsArray = $tagsValue->tags;

            if (is_array($tagsArray)) {

                foreach ($tagsArray as $existingTag) {
                    $output->writeln("<info>" . $existingTag->getKeyword());
                }

                // clear taglist if override
                if ($override) {
                    $tagsArray = array();
                }

                // add the tags to the tagsarray
                foreach($inputKeywords as $inputKeyword) {

                    $inputKeyword = trim($inputKeyword);

                    // test, if tag allready present in content
                    $tagExist = false;
                    foreach ($tagsArray as $existingTag) {
                        if($existingTag->getKeyword() === $inputKeyword){
                            $output->writeln("<error>$inputKeyword ist bereits beim Content vorhanden </error>");
                            $tagExist = true;
                        }
                    }

                    if (!$tagExist) {
                        // search for the tag
                        $output->writeln("<info>suche Tag $inputKeyword");
                        $exists = $tagsService->loadTagsByKeyword($inputKeyword, "ger-DE");

                        if (!empty($exists)) {
                            $tag = $exists[0];
                            $output->writeln("<info>Tag gefunden: " . $tag->getKeyword() . " mit id " . $tag->__get("id"));
                            $tagsArray[] = $tag;

                        } else {
                            $output->writeln("<error>Tag $inputKeyword existiert nicht");
                        }
                    }
                }

                // save new taglist
                $tagsValue->tags = $tagsArray;
                $contentUpdateStruct = $contentService->newContentUpdateStruct();
                $contentUpdateStruct->setField($fieldDefIdentifier, $tagsValue);
                $contentDraft = $contentService->createContentDraft($contentInfo);
                $contentService->updateContent($contentDraft->getVersionInfo(), $contentUpdateStruct);
                $contentService->publishVersion($contentDraft->getVersionInfo());


            } else {
                $output->writeln("<error>$fieldDefIdentifier ist kein array");
            }
        } catch (NotFoundException $e) {
            $output->writeln("<error>Content mit id $inputContentId existiert nicht");
        }
    }
}
