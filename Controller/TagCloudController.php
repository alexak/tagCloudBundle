<?php

namespace Raumobil\HomeAndSmart\TagCloudBundle\Controller;

use AppBundle\Traits\esiResponseTrait;
use eZ\Bundle\EzPublishCoreBundle\Controller;
use Raumobil\HomeAndSmart\TagCloudBundle\Helper\TagCloudHelper;

class TagCloudController extends Controller
{
    use esiResponseTrait;

    public function ArticleTagsAction($articleId, $zonenId = null)
    {
        $repository = $this->getRepository();
        $contentService = $repository->getContentService();
        $article = $contentService->loadContent($articleId);

        if($article) {
            $tagCloudHelper = $this->container->get("raumobil_home_and_smart.tag.helper");
            $tagsArr = $tagCloudHelper->getTags($article);
        } else {
            $tagsArr = array();
        }

        return $this->render('RaumobilHomeAndSmartTagCloudBundle:Default:hs_tagCloud.html.twig', array(
            'tags' => $tagsArr,
            'zonenId' => $zonenId
        ), $this->getResponse());
    }


    public function ArticleStaticTagsAction($articleId = null, $zonenId)
    {
        $repository = $this->getRepository();
        $contentService = $repository->getContentService();

        if(is_null($articleId)){
            $article = null;
        } else {
            $article = $contentService->loadContent($articleId);
        }

        $tagCloudHelper = $this->container->get("raumobil_home_and_smart.tag.helper");

        if(($article)&&!is_null($article)) {
            $tagsArr = $tagCloudHelper->getStaticTags($article, null);
        } else {
            $tagsArr = $tagCloudHelper->getStaticTags(null, null);
        }

        $twigValues = array(
            'tags' => $tagsArr,
            'parentName' => 'Top Themen',
            'zonenId' => $zonenId
        );

        return $this->render('RaumobilHomeAndSmartTagCloudBundle:Default:hs_tagCloud.html.twig', $twigValues, $this->getResponse());
    }
}
