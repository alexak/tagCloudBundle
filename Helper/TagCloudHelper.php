<?php


namespace Raumobil\HomeAndSmart\TagCloudBundle\Helper;

use Symfony\Component\Yaml\Yaml;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use Symfony\Component\DependencyInjection\Container;
use eZ\Publish\API\Repository\SearchService;


/**
 * Created by PhpStorm.
 * User: alex
 * Date: 08.12.16
 * Time: 10:00
 */
class TagCloudHelper
{
    private $tagService;
    private $contentService;
    private $contentTypeService;
    private $searchService;
    private $container;
    private $cachePool;
    private $allowedTags =[];

    public function __construct(
        $tagService,
        $contentService,
        $contentTypeService,
        $searchService,
        $container,
        $cachePool
    )
    {
        $this->tagService         = $tagService;
        $this->contentService     = $contentService;
        $this->contentTypeService = $contentTypeService;
        $this->searchService      = $searchService;
        $this->container          = $container;
        $this->cachePool          = $cachePool;
    }


    /**
     * Function that gets the tags from the (main-) content object passed as parameter. The field major_tags is read,
     * then each tag is compared with the config file, in order to filter invisible tags..
     *
     * @param $mainContent object of the maincontent (article)
     * @return array of tags. Each tag has a value/pais of keyword and id
     */
    public function getTags($mainContent)
    {
        $configs = Yaml::parse(file_get_contents(__DIR__.'/../../../HomeAndSmartBundle/Resources/config/tagList.yml'));
        $tagConfigs = array();

        foreach($configs as $configKey => $configValues){
            foreach ($configValues as $configValueKey => $configValueV){
                $tagConfigs[$configValueKey] = $configValueV;
            }
        }

        // use mojortags of maincontent
        $majorTagsUnfiltered = $mainContent->getFieldValue('major_tags')->tags;
        $majorTagIds = $this->filterTags($majorTagsUnfiltered);
        $majorTagsArr = array();
        foreach ($majorTagIds as $majorTagId){
            $keyword = trim($this->getKeyword($majorTagId));
            if(!in_array($keyword, $majorTagsArr)){
                $majorTagsArr[] = trim($keyword);
            }
        }

        // use tags of maincontent
        $tagsUnfiltered = $mainContent->getFieldValue('tags')->tags;
        $tagIds = $this->filterTags($tagsUnfiltered);
        $tagsArr = array();
        foreach ($tagIds as $tagId){
            $keyword = trim($this->getKeyword($tagId));
            if(!in_array($keyword, $majorTagsArr)){
                if(!isset($tagsArr[$keyword])){
                    if(isset($tagConfigs[$keyword])){
                        if ($tagConfigs[$keyword]['isVisible'] === true){
                            $tagsArr[$keyword] = array(
                                'keyword' => $keyword,
                                'id'  => $tagId
                            );
                        }
                    } else {
                        $tagsArr[$keyword] = array(
                            'keyword' => $keyword,
                            'id'  => $tagId
                        );
                    }
                }
            }
        }

        return $tagsArr;
    }


    /**
     * Function that gets the static tags from the given tag list.
     * If no tag list is provided, a default list is loaded.
     * The hidden and already given tags of the maincontent
     * are filtered - the function returns all tags from the config file that are visible,
     * declared as static and not present in the (main-) content object
     *
     * @param $mainContent : maincontent (an hs article object)
     * @param $tagCloudId: id of the tagcloud template
     * @return array of tags. Each tag has a value/pair of keyword and id
     */
    public function getStaticTags($mainContent, $tagCloudId)
    {
        $tagsArr = array();

        // get tag list by passed content id ..
       if (isset($tagCloudId) && !empty($tagCloudId)){
           try{
               $contentValue = $this->contentService->loadContent( $tagCloudId );
               // test, if contenttype ok..
               $contentType = $this->contentTypeService->loadContentType($contentValue->contentInfo->contentTypeId);

           // nothing found .. reset to null ..
           } catch(\eZ\Publish\API\Repository\Exceptions\NotFoundException $e) {
               $tagCloudId = null;
           }
       }

        // fallback if wrong content type or no tag list given ...
       if (empty($tagCloudId) || $contentType->identifier != "hs_tagcloud") {
            $contentValue = $this->getDefaultTagsContent();
       }

        if(!empty($contentValue)){
            $tagsUnfiltered = $contentValue->getFieldValue('tags')->tags;
            $tagIds = $this->filterTags($tagsUnfiltered);

            // List with default tags..
            foreach($tagIds as $tagId){
                $keyword = trim($this->getKeyword($tagId));
                $tagsArr[$keyword] = array(
                    'keyword' => $keyword,
                    'id'  => $tagId
                );
            }

            // delete major tags from list ....
            if (!empty($mainContent)) {
                $majorTagsUnfiltered = $mainContent->getFieldValue('major_tags')->tags;
                $majorTagIds = $this->filterTags($majorTagsUnfiltered);
                foreach ($majorTagIds as $majorTagId){
                    $keyword = trim($this->getKeyword($majorTagId));
                    unset($tagsArr[$keyword]);
                }
            }
        }

       return $tagsArr;
    }


    private function getDefaultTagsContent()
    {
        $contentType = 'hs_tagcloud';

        try {
            $content = $this->contentTypeService->loadContentTypeByIdentifier($contentType);
        } catch(\eZ\Publish\API\Repository\Exceptions\NotFoundException $e){
            return null;
        }

        $query = new Query();
        $query->filter = new Criterion\LogicalAnd([
            new Criterion\ContentTypeIdentifier([$contentType]),
            new Criterion\Field('internal_name', Criterion\Operator::EQ, '_default_tags')
        ]);

        $searchResult = $this->searchService->findContent($query);
        if(0 < count($searchResult->searchHits)){
            return $searchResult->searchHits[0]->valueObject;
        } else {
            return null;
        }
    }


    /**
     * function that returns allchild tags from a given tag
     * @param $tagSubtree
     * @param $tag
     * @return mixed
     */
    private function getChildTags($tagSubtree, $tag)
    {
        $tagSubtree[$tag->id] = $tag;
        $tags = $this->tagService->loadTagChildren($tag);
        foreach($tags as $tag){
            $tagSubtree = $this->getChildTags($tagSubtree, $tag);
        }

        return $tagSubtree;
    }


    /**
     * function, that tests, if a  content is part of allowed tags
     */
    public function isAllowedContent($content)
    {
        $mainTags = null;
        if ($content->getField('major_tags')) {
            $mainTags = $content->getFieldValue('major_tags');

        } elseif ($content->getField('main_tag')) {
            $mainTags = $content->getFieldValue('main_tag');
        }

        // no main tag -> show page
        if(empty($mainTags)){
            return true;

        // field maintags exists but is empty
        } else if (empty($mainTags->tags)) {
            return true;

        } else {
            $allowedTags = $this->getAllowedTags();
            foreach ($mainTags->tags as $tag) {
                if (array_key_exists($tag->id, $allowedTags)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * function that filters a tag list.
     * Non allowed Tags will be deleted from list
     * @param array $tags might be array of IDs or OBJECTS
     * @return array of IDs
     */
    public function filterTags($tags = [])
    {
        $allAllowedTagIds = $this->getAllowedTagIds();
        $allowedTagIds = [];
        if (empty($tags)){
            $allowedTagIds = $allAllowedTagIds;
        } else {
            foreach($tags as $tag){
                $tagId = is_object($tag) ? $tag->id : $tag;
                if (in_array($tagId, $allAllowedTagIds)) {
                    $allowedTagIds[] = $tagId;
                }
            }
        }

        return $allowedTagIds;
    }


    /**
     * function that returns an array with allowed Tagids
     * If the lits is empty, a new one will be created
     */
    public function getAllowedTagIds()
    {
        return array_keys($this->getAllowedTags());
    }


    /**
     * function that returns an array with allowed Tagids
     */
    public function initAllowedTags()
    {
      $siteAccessHelper = $this->container->get('raumobil_home_and_smart.siteacess.helper');
      $siteaccessOptions = $siteAccessHelper->getSiteAccessInfo();
      $name = $siteaccessOptions->getFieldValue('name')->text;
      $version = $siteaccessOptions->getVersionInfo()->versionNo;

      // check if allowed tags were already cached
      // use siteaccess name and version in key to ensure changes are applied
      // TODO invalidate cache instead?
      $cachedAllowedTags = $this->cachePool->getItem('hs.'.$name.'.allowedTags.'.$version);
      if (!$cachedAllowedTags->isHit()) {
          $allowedTags = [];
          if ($siteaccessOptions->getFieldValue('wlTags')) {
            foreach($siteaccessOptions->getFieldValue('wlTags')->tags as $parentTag){
                $allowedTags = $this->getChildTags($allowedTags, $parentTag);
            }
          }
          $cachedAllowedTags->set($allowedTags);
          $this->cachePool->save($cachedAllowedTags);
      }

      return $cachedAllowedTags->get();
    }


    /**
     * function that gets a keyword from a tag id
     * @param integer $tagId
     */
    public function getKeyword($tagId)
    {
        return $this->getAllowedTags()[$tagId]->keywords['ger-DE'];
    }

    /**
     * function that test, if a link points to an alowed content
     */
    public function isAllowedPath($path)
    {
        $isAllowed = true;
        $path = trim($path,'/');
        if (strpos ($path, '/') === false){
            /* @var $query Query\ */
            $query = new Query();
            $query->filter = new Criterion\LogicalAnd([
                new Criterion\Field('url_string', Criterion\Operator::EQ, $path),
                new Criterion\ContentTypeIdentifier(array('landing_page', 'hs_article')),
                new Criterion\Visibility(Criterion\Visibility::VISIBLE)
            ]);

            // search for article / landing page
            $searchResult = $this->searchService->findContent($query);
            if($searchResult->totalCount == 1){
                $content = $searchResult->searchHits[0]->valueObject;
                $isAllowed = $this->isAllowedContent($content);
            }
        }

        return $isAllowed;
    }

    /**
     * function that gets array of tag objects (singleton pattern)
     * @return array
     */
    private function getAllowedTags()
    {
        if(empty($this->allowedTags)){
            $this->allowedTags = $this->initAllowedTags();
        }

        return $this->allowedTags;
    }
}
