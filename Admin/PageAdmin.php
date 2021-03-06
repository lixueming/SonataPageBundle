<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Admin;

use Sonata\AdminBundle\Admin\Admin;
use Sonata\AdminBundle\Admin\AdminInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Validator\ErrorElement;

use Sonata\PageBundle\Exception\PageNotFoundException;
use Sonata\PageBundle\Exception\InternalErrorException;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\PageManagerInterface;
use Sonata\PageBundle\Model\SiteManagerInterface;

use Sonata\CacheBundle\Cache\CacheManagerInterface;

use Knp\Menu\ItemInterface as MenuItemInterface;

/**
 * Admin definition for the Page class
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class PageAdmin extends Admin
{
    protected $pageManager;

    protected $siteManager;

    protected $cacheManager;

    /**
     * {@inheritdoc}
     */
    protected function configureShowFields(ShowMapper $showMapper)
    {
        $showMapper
            ->add('site')
            ->add('routeName')
            ->add('pageAlias')
            ->add('enabled')
            ->add('decorate')
            ->add('name')
            ->add('slug')
            ->add('customUrl')
            ->add('edited')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper
            ->add('hybrid', 'text', array('template' => 'SonataPageBundle:PageAdmin:field_hybrid.html.twig'))
            ->addIdentifier('name')
            ->add('pageAlias')
            ->add('site')
            ->add('decorate', null, array('editable' => true))
            ->add('enabled', null, array('editable' => true))
            ->add('edited', null, array('editable' => true))
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper
            ->add('site')
            ->add('name')
            ->add('pageAlias')
            ->add('edited')
            ->add('hybrid', 'doctrine_orm_callback', array(
                'callback' => function($queryBuilder, $alias, $field, $data) {
                    if (in_array($data['value'], array('hybrid', 'cms'))) {
                        $queryBuilder->andWhere(sprintf('%s.routeName %s :routeName', $alias, $data['value'] == 'cms' ? '=' : '!='));
                        $queryBuilder->setParameter('routeName', PageInterface::PAGE_ROUTE_CMS_NAME);
                    }
                },
                'field_options' => array(
                    'required' => false,
                    'choices'  => array(
                        'hybrid'  => $this->trans('hybrid'),
                        'cms'     => $this->trans('cms'),
                    )
                ),
                'field_type' => 'choice'
            ))
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureFormFields(FormMapper $formMapper)
    {
        if (!$this->getSubject() || (!$this->getSubject()->isInternal() && !$this->getSubject()->isError())) {
            $formMapper
                ->with($this->trans('form_page.group_main_label'))
                    ->add('url', 'text', array('attr' => array('readonly' => 'readonly')))
                ->end()
            ;
        }

        $formMapper
            ->with($this->trans('form_page.group_main_label'))
                ->add('site')
                ->add('name')
                ->add('enabled', null, array('required' => false))
                ->add('position')
                ->add('templateCode', 'sonata_page_template', array('required' => true))
                ->add('parent', 'sonata_page_selector', array(
                    'page'          => $this->getSubject() ?: null,
                    'site'          => $this->getSubject() ? $this->getSubject()->getSite() : null,
                    'model_manager' => $this->getModelManager(),
                    'class'         => $this->getClass(),
                    'filter_choice' => array('hierarchy' => 'root'),
                    'required'      => false
                ))
            ->end()
        ;

        if (!$this->getSubject() || !$this->getSubject()->isDynamic()) {
            $formMapper
                ->with($this->trans('form_page.group_main_label'))
                    ->add('pageAlias', null, array('required' => false))
                    ->add('target', 'sonata_page_selector', array(
                        'page'          => $this->getSubject() ?: null,
                        'site'          => $this->getSubject() ? $this->getSubject()->getSite() : null,
                        'model_manager' => $this->getModelManager(),
                        'class'         => $this->getClass(),
                        'filter_choice' => array('request_method' => 'all'),
                        'required'      => false
                    ))
                ->end()
            ;
        }

        if (!$this->getSubject() || !$this->getSubject()->isHybrid()) {
            $formMapper
                ->with($this->trans('form_page.group_seo_label'))
                    ->add('slug', 'text',  array('required' => false))
                    ->add('customUrl', 'text', array('required' => false))
                ->end()
            ;
        }

        $formMapper
            ->with($this->trans('form_page.group_seo_label'), array('collapsed' => true))
                ->add('title', null, array('required' => false))
                ->add('metaKeyword', 'textarea', array('required' => false))
                ->add('metaDescription', 'textarea', array('required' => false))
            ->end()
        ;

        if ($this->hasSubject() && !$this->getSubject()->isCms()) {
            $formMapper
                ->with($this->trans('form_page.group_advanced_label'), array('collapsed' => true))
                    ->add('decorate', null,  array('required' => false))
                ->end();
        }

        $formMapper
            ->with($this->trans('form_page.group_advanced_label'), array('collapsed' => true))
                ->add('javascript', null,  array('required' => false))
                ->add('stylesheet', null, array('required' => false))
                ->add('rawHeaders', null, array('required' => false))
            ->end()
        ;

        $formMapper->setHelps(array(
            'name' => $this->trans('help_page_name')
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function validate(ErrorElement $errorElement, $object)
    {
        $this->pageManager->fixUrl($object);

        $pages = $this->pageManager->findBy(array(
            'site' => $object->getSite(),
            'url'  => $object->getUrl()
        ));

        if ($object->isError()) {
            return;
        }

        foreach ($pages as $page) {
            if ($page->getUrl() == $object->getUrl() && $page != $object) {
                $errorElement->addViolation($this->trans('error.uniq_url', array('%url%' => $object->getUrl())));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function configureSideMenu(MenuItemInterface $menu, $action, AdminInterface $childAdmin = null)
    {
        if (!$childAdmin && !in_array($action, array('edit'))) {
            return;
        }

        $admin = $this->isChild() ? $this->getParent() : $this;

        $id = $admin->getRequest()->get('id');

        $menu->addChild(
            $this->trans('sidemenu.link_edit_page'),
            array('uri' => $admin->generateUrl('edit', array('id' => $id)))
        );

        $menu->addChild(
            $this->trans('sidemenu.link_list_blocks'),
            array('uri' => $admin->generateUrl('sonata.page.admin.block.list', array('id' => $id)))
        );

        $menu->addChild(
            $this->trans('sidemenu.link_list_snapshots'),
            array('uri' => $admin->generateUrl('sonata.page.admin.snapshot.list', array('id' => $id)))
        );

        if (!$this->getSubject()->isHybrid()) {

            try {
                $menu->addChild(
                    $this->trans('view_page'),
                    array('uri' => $this->getRouteGenerator()->generate('page_slug', array('path' => $this->getSubject()->getUrl())))
                );
            } catch (RouteNotFoundException $e) {
                // avoid crashing the admin if the route is not setup correctly
//                throw $e;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function postUpdate($object)
    {
        if ($this->cacheManager) {
            $this->cacheManager->invalidate(array(
                'page_id' => $object->getId()
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update($object)
    {
        $object->setEdited(true);

        $this->preUpdate($object);
        $this->pageManager->save($object);
        $this->postUpdate($object);
    }

    /**
     * {@inheritdoc}
     */
    public function create($object)
    {

        $object->setEdited(true);

        $this->prePersist($object);
        $this->pageManager->save($object);
        $this->postPersist($object);
    }

    /**
     * @param \Sonata\PageBundle\Model\PageManagerInterface $pageManager
     */
    public function setPageManager(PageManagerInterface $pageManager)
    {
        $this->pageManager = $pageManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getNewInstance()
    {
        $instance = parent::getNewInstance();

        if (!$this->hasRequest()) {
            return $instance;
        }

        if ($site = $this->getSite()) {
            $instance->setSite($site);
        }

        if ($site && $this->getRequest()->get('url')) {
            $slugs = explode('/', $this->getRequest()->get('url'));
            $slug  = array_pop($slugs);

            try {
                $parent = $this->pageManager->getPageByUrl($site, implode('/', $slugs));
            } catch (PageNotFoundException $e) {
                try {
                    $parent = $this->pageManager->getPageByUrl($site, '/');
                } catch (PageNotFoundException $e) {
                    throw new InternalErrorException('Unable to find the root url, please create a route with url = /');
                }
            }

            $instance->setSlug(urldecode($slug));
            $instance->setParent($parent ?: null);
            $instance->setName(urldecode($slug));
        }

        return $instance;
    }

    /**
     * @return SiteInterface
     *
     * @throws \RuntimeException
     */
    public function getSite()
    {
        if (!$this->hasRequest()) {
            return false;
        }

        $siteId = null;

        if ($this->getRequest()->getMethod() == 'POST') {
            $values = $this->getRequest()->get($this->getUniqid());
            $siteId = isset($values['site']) ? $values['site'] : null;
        }

        $siteId = (null !== $siteId) ? $siteId : $this->getRequest()->get('siteId');

        if ($siteId) {
            $site = $this->siteManager->findOneBy(array('id' => $siteId));

            if (!$site) {
                throw new \RuntimeException('Unable to find the site with id=' . $this->getRequest()->get('siteId'));
            }

            return $site;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getBatchActions()
    {
        $actions = parent::getBatchActions();

        $actions['snapshot'] = array(
            'label'            => $this->trans('create_snapshot'),
            'ask_confirmation' => true
        );

        return $actions;
    }

    /**
     * @param \Sonata\PageBundle\Model\SiteManagerInterface $siteManager
     */
    public function setSiteManager(SiteManagerInterface $siteManager)
    {
        $this->siteManager = $siteManager;
    }

    /**
     * @return array
     */
    public function getSites()
    {
        return $this->siteManager->findBy();
    }

    /**
     * @param \Sonata\CacheBundle\Cache\CacheManagerInterface $cacheManager
     */
    public function setCacheManager(CacheManagerInterface $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }
}