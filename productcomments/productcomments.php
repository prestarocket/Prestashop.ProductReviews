<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class ProductComments extends Module implements WidgetInterface
{
    const INSTALL_SQL_FILE = 'install.sql';

    private $_html = '';
    private $_postErrors = array();
    private $_filters = array();

    private $_productCommentsCriterionTypes = array();
    private $_baseUrl;

    public function __construct()
    {
        $this->name = 'productcomments';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'phinq1910';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->_setFilters();

        parent::__construct();

        $this->secure_key = Tools::encrypt($this->name);

        $this->displayName = $this->trans('Product Comments', array(), 'Modules.Productcomments.Admin');
        $this->description = $this->trans('Allows users to post reviews and rate products on specific criteria.', array(), 'Modules.Productcomments.Admin');

        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => '1.7.99.99');
    }

    public function install($keep = true)
    {
        if ($keep) {
            if (!file_exists(dirname(__FILE__).'/'.self::INSTALL_SQL_FILE)) {
                return false;
            } elseif (!$sql = file_get_contents(dirname(__FILE__).'/'.self::INSTALL_SQL_FILE)) {
                return false;
            }
            $sql = str_replace(array('PREFIX_', 'ENGINE_TYPE'), array(_DB_PREFIX_, _MYSQL_ENGINE_), $sql);
            $sql = preg_split("/;\s*[\r\n]+/", trim($sql));

            foreach ($sql as $query) {
                if (!Db::getInstance()->execute(trim($query))) {
                    return false;
                }
            }
        }

        if (parent::install() == false ||
            !$this->registerHook('displayReassurance') ||
            !$this->registerHook('extraProductComparison') ||
            !$this->registerHook('displayFooterProduct') ||
            !$this->registerHook('header') ||
            !$this->registerHook('displayRightColumnProduct') ||
            !$this->registerHook('displayProductListReviews') ||
            !Configuration::updateValue('PRODUCT_COMMENTS_MINIMAL_TIME', 30) ||
            !Configuration::updateValue('PRODUCT_COMMENTS_ALLOW_GUESTS', 0) ||
            !Configuration::updateValue('PRODUCT_COMMENTS_AUTO_APPROVE', 0) ||
            !Configuration::updateValue('PRODUCT_COMMENTS_MODERATE', 1)) {
            return false;
        }

        return true;
    }

    public function uninstall($keep = true)
    {
        if (!parent::uninstall() || ($keep && !$this->deleteTables()) ||
            !Configuration::deleteByName('PRODUCT_COMMENTS_MODERATE') ||
            !Configuration::deleteByName('PRODUCT_COMMENTS_ALLOW_GUESTS') ||
            !Configuration::deleteByName('PRODUCT_COMMENTS_MINIMAL_TIME') ||
            !Configuration::deleteByName('PRODUCT_COMMENTS_AUTO_APPROVE') ||
            !$this->unregisterHook('extraProductComparison') ||
            !$this->unregisterHook('displayRightColumnProduct') ||
            !$this->unregisterHook('displayReassurance') ||
            !$this->unregisterHook('header') ||
            !$this->unregisterHook('displayFooterProduct') ||
            !$this->unregisterHook('top') ||
            !$this->unregisterHook('displayProductListReviews')) {
            return false;
        }

        return true;
    }

    public function renderWidget($hookName = null, array $configuration = [])
    {

    }

    public function getWidgetVariables($hookName = null, array $configuration = [])
    {

    }

    public function reset()
    {
        if (!$this->uninstall(false)) {
            return false;
        }
        if (!$this->install(false)) {
            return false;
        }

        return true;
    }

    public function deleteTables()
    {
        return Db::getInstance()->execute('
			DROP TABLE IF EXISTS
			`'._DB_PREFIX_.'product_comment`,
			`'._DB_PREFIX_.'product_comment_criterion`,
			`'._DB_PREFIX_.'product_comment_criterion_product`,
			`'._DB_PREFIX_.'product_comment_criterion_lang`,
			`'._DB_PREFIX_.'product_comment_criterion_category`,
			`'._DB_PREFIX_.'product_comment_grade`,
			`'._DB_PREFIX_.'product_comment_usefulness`,
			`'._DB_PREFIX_.'product_comment_report`');
    }

    public function getCacheId($id_product = null)
    {
        return parent::getCacheId().'|'.(int) $id_product;
    }

    protected function _postProcess()
    {
        $this->_setFilters();

        if (Tools::isSubmit('submitModerate')) {
            Configuration::updateValue('PRODUCT_COMMENTS_AUTO_APPROVE', (int) Tools::getValue('PRODUCT_COMMENTS_AUTO_APPROVE'));
            Configuration::updateValue('PRODUCT_COMMENTS_MODERATE', (int) Tools::getValue('PRODUCT_COMMENTS_MODERATE'));
            Configuration::updateValue('PRODUCT_COMMENTS_ALLOW_GUESTS', (int) Tools::getValue('PRODUCT_COMMENTS_ALLOW_GUESTS'));
            Configuration::updateValue('PRODUCT_COMMENTS_MINIMAL_TIME', (int) Tools::getValue('PRODUCT_COMMENTS_MINIMAL_TIME'));
            $this->_html .= '<div class="conf confirm alert alert-success">'.$this->trans('Settings updated').'</div>';
        } elseif (Tools::isSubmit('productcomments')) {
            $id_product_comment = (int) Tools::getValue('id_product_comment');
            $comment = new ProductComment($id_product_comment);
            $comment->validate();
            ProductComment::deleteReports($id_product_comment);
        } elseif (Tools::isSubmit('deleteproductcomments')) {
            $id_product_comment = (int) Tools::getValue('id_product_comment');
            $comment = new ProductComment($id_product_comment);
            $comment->delete();
        } elseif (Tools::isSubmit('submitEditCriterion')) {
            $criterion = new ProductCommentCriterion((int) Tools::getValue('id_product_comment_criterion'));
            $criterion->id_product_comment_criterion_type = Tools::getValue('id_product_comment_criterion_type');
            $criterion->active = Tools::getValue('active');

            $languages = Language::getLanguages();
            $name = array();
            foreach ($languages as $key => $value) {
                $name[$value['id_lang']] = Tools::getValue('name_'.$value['id_lang']);
            }
            $criterion->name = $name;

            $criterion->save();

            // Clear before reinserting data
            $criterion->deleteCategories();
            $criterion->deleteProducts();
            if ($criterion->id_product_comment_criterion_type == 2) {
                if ($categories = Tools::getValue('categoryBox')) {
                    if (count($categories)) {
                        foreach ($categories as $id_category) {
                            $criterion->addCategory((int) $id_category);
                        }
                    }
                }
            } elseif ($criterion->id_product_comment_criterion_type == 3) {
                if ($products = Tools::getValue('ids_product')) {
                    if (count($products)) {
                        foreach ($products as $product) {
                            $criterion->addProduct((int) $product);
                        }
                    }
                }
            }
            if ($criterion->save()) {
                Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminModules').'&configure='.$this->name.'&conf=4');
            } else {
                $this->_html .= '<div class="conf confirm alert alert-danger">'.$this->trans('The criterion could not be saved').'</div>';
            }
        } elseif (Tools::isSubmit('deleteproductcommentscriterion')) {
            $productCommentCriterion = new ProductCommentCriterion((int) Tools::getValue('id_product_comment_criterion'));
            if ($productCommentCriterion->id) {
                if ($productCommentCriterion->delete()) {
                    $this->_html .= '<div class="conf confirm alert alert-success">'.$this->trans('Criterion deleted').'</div>';
                }
            }
        } elseif (Tools::isSubmit('statusproductcommentscriterion')) {
            $criterion = new ProductCommentCriterion((int) Tools::getValue('id_product_comment_criterion'));
            if ($criterion->id) {
                $criterion->active = (int) (!$criterion->active);
                $criterion->save();
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules').'&configure='.$this->name.'&tab_module='.$this->tab.'&conf=4&module_name='.$this->name);
        } elseif ($id_product_comment = (int) Tools::getValue('approveComment')) {
            $comment = new ProductComment($id_product_comment);
            $comment->validate();
        } elseif ($id_product_comment = (int) Tools::getValue('noabuseComment')) {
            ProductComment::deleteReports($id_product_comment);
        }

        $this->_clearcache('productcomments_reviews.tpl');
    }

    public function getContent()
    {
        include_once dirname(__FILE__).'/ProductComment.php';
        include_once dirname(__FILE__).'/ProductCommentCriterion.php';

        $this->_html = '';
        if (Tools::isSubmit('updateproductcommentscriterion')) {
            $this->_html .= $this->renderCriterionForm((int) Tools::getValue('id_product_comment_criterion'));
        } else {
            $this->_postProcess();
            $this->_html .= $this->renderConfigForm();
            $this->_html .= $this->renderModerateLists();
            $this->_html .= $this->renderCriterionList();
            $this->_html .= $this->renderCommentsList();
        }

        $this->_setBaseUrl();
        $this->_productCommentsCriterionTypes = ProductCommentCriterion::getTypes();

        $this->context->controller->addJs($this->_path.'js/moderate.js');

        return $this->_html;
    }

    private function _setBaseUrl()
    {
        $this->_baseUrl = 'index.php?';
        foreach ($_GET as $k => $value) {
            if (!in_array($k, array('deleteCriterion', 'editCriterion'))) {
                $this->_baseUrl .= $k.'='.$value.'&';
            }
        }
        $this->_baseUrl = rtrim($this->_baseUrl, '&');
    }

    public function renderConfigForm()
    {
        $fields_form_1 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Configuration', array(), 'Modules.Productcomments.Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->trans('Auto approve comment', array(), 'Modules.Productcomments.Settings'),
                        'name' => 'PRODUCT_COMMENTS_AUTO_APPROVE',
                        'values' => array(
                                        array(
                                            'id' => 'active_on',
                                            'value' => 1,
                                            'label' => $this->trans('Enabled', array(), 'Modules.Productcomments.Settings'),
                                        ),
                                        array(
                                            'id' => 'active_off',
                                            'value' => 0,
                                            'label' => $this->trans('Disabled', array(), 'Modules.Productcomments.Settings'),
                                        ),
                                    ),
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true, //retro compat 1.5
                        'label' => $this->trans('All reviews must be validated by an employee', array(), 'Modules.Productcomments.Settings'),
                        'name' => 'PRODUCT_COMMENTS_MODERATE',
                        'values' => array(
                                        array(
                                            'id' => 'active_on',
                                            'value' => 1,
                                            'label' => $this->trans('Enabled', array(), 'Modules.Productcomments.Settings'),
                                        ),
                                        array(
                                            'id' => 'active_off',
                                            'value' => 0,
                                            'label' => $this->trans('Disabled', array(), 'Modules.Productcomments.Settings'),
                                        ),
                                    ),
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true, //retro compat 1.5
                        'label' => $this->trans('Allow guest reviews', array(), 'Modules.Productcomments.Settings'),
                        'name' => 'PRODUCT_COMMENTS_ALLOW_GUESTS',
                        'values' => array(
                                        array(
                                            'id' => 'active_on',
                                            'value' => 1,
                                            'label' => $this->trans('Enabled', array(), 'Modules.Productcomments.Settings'),
                                        ),
                                        array(
                                            'id' => 'active_off',
                                            'value' => 0,
                                            'label' => $this->trans('Disabled', array(), 'Modules.Productcomments.Settings'),
                                        ),
                                    ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Minimum time between 2 reviews from the same user', array(), 'Modules.Productcomments.Settings'),
                        'name' => 'PRODUCT_COMMENTS_MINIMAL_TIME',
                        'class' => 'fixed-width-xs',
                        'suffix' => 'seconds',
                    ),
                ),
            'submit' => array(
                'title' => $this->trans('Save', array(), 'Modules.Productcomments.Settings'),
                'class' => 'btn btn-default pull-right',
                'name' => 'submitModerate',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->name;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitProducCommentsConfiguration';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields_form_1));
    }

    public function renderModerateLists()
    {
        require_once dirname(__FILE__).'/ProductComment.php';
        $return = null;

        if (Configuration::get('PRODUCT_COMMENTS_MODERATE')) {
            $comments = ProductComment::getByValidate(0, false);

            $fields_list = $this->getStandardFieldList();

            if (version_compare(_PS_VERSION_, '1.6', '<')) {
                $return .= '<h1>'.$this->trans('Reviews waiting for approval').'</h1>';
                $actions = array('enable', 'delete');
            } else {
                $actions = array('approve', 'delete');
            }

            $helper = new HelperList();
            $helper->shopLinkType = '';
            $helper->simple_header = true;
            $helper->actions = $actions;
            $helper->show_toolbar = false;
            $helper->module = $this;
            $helper->listTotal = count($comments);
            $helper->identifier = 'id_product_comment';
            $helper->title = $this->trans('Reviews waiting for approval');
            $helper->table = $this->name;
            $helper->token = Tools::getAdminTokenLite('AdminModules');
            $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
            //$helper->tpl_vars = array('priority' => array($this->trans('High'), $this->trans('Medium'), $this->trans('Low')));

            $return .= $helper->generateList($comments, $fields_list);
        }

        $comments = ProductComment::getReportedComments();

        $fields_list = $this->getStandardFieldList();

        if (version_compare(_PS_VERSION_, '1.6', '<')) {
            $return .= '<h1>'.$this->trans('Reported Reviews', array(), 'Modules.Productcomments.Admin').'</h1>';
            $actions = array('enable', 'delete');
        } else {
            $actions = array('delete', 'noabuse');
        }

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->actions = $actions;
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->listTotal = count($comments);
        $helper->identifier = 'id_product_comment';
        $helper->title = $this->trans('Reported Reviews');
        $helper->table = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        //$helper->tpl_vars = array('priority' => array($this->trans('High'), $this->trans('Medium'), $this->trans('Low')));

        $return .= $helper->generateList($comments, $fields_list);

        return $return;
    }

    public function renderCriterionList()
    {
        include_once dirname(__FILE__).'/ProductCommentCriterion.php';

        $criterions = ProductCommentCriterion::getCriterions($this->context->language->id, false, false);

        $fields_list = array(
            'id_product_comment_criterion' => array(
                'title' => $this->trans('ID', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'name' => array(
                'title' => $this->trans('Name', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'type_name' => array(
                'title' => $this->trans('Type', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'active' => array(
                'title' => $this->trans('Status', array(), 'Modules.Productcomments.Admin'),
                'active' => 'status',
                'type' => 'bool',
            ),
        );

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->actions = array('edit', 'delete');
        $helper->show_toolbar = true;
        $helper->toolbar_btn['new'] = array(
            'href' => $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name.'&module_name='.$this->name.'&updateproductcommentscriterion',
            'desc' => $this->l('Add New Criterion', null, null, false),
        );
        $helper->module = $this;
        $helper->identifier = 'id_product_comment_criterion';
        $helper->title = $this->trans('Review Criteria', array(), 'Modules.Productcomments.Admin');
        $helper->table = $this->name.'criterion';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        //$helper->tpl_vars = array('priority' => array($this->trans('High'), $this->trans('Medium'), $this->trans('Low')));

        return $helper->generateList($criterions, $fields_list);
    }

    public function renderCommentsList()
    {
        require_once dirname(__FILE__).'/ProductComment.php';

        $comments = ProductComment::getByValidate(1, false);
        $moderate = Configuration::get('PRODUCT_COMMENTS_MODERATE');
        if (empty($moderate)) {
            $comments = array_merge($comments, ProductComment::getByValidate(0, false));
        }

        $fields_list = $this->getStandardFieldList();

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->actions = array('delete');
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->listTotal = count($comments);
        $helper->identifier = 'id_product_comment';
        $helper->title = $this->trans('Approved Reviews');
        $helper->table = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        //$helper->tpl_vars = array('priority' => array($this->trans('High'), $this->trans('Medium'), $this->trans('Low')));

        return $helper->generateList($comments, $fields_list);
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PRODUCT_COMMENTS_MODERATE' => Tools::getValue('PRODUCT_COMMENTS_MODERATE', Configuration::get('PRODUCT_COMMENTS_MODERATE')),
            'PRODUCT_COMMENTS_ALLOW_GUESTS' => Tools::getValue('PRODUCT_COMMENTS_ALLOW_GUESTS', Configuration::get('PRODUCT_COMMENTS_ALLOW_GUESTS')),
            'PRODUCT_COMMENTS_MINIMAL_TIME' => Tools::getValue('PRODUCT_COMMENTS_MINIMAL_TIME', Configuration::get('PRODUCT_COMMENTS_MINIMAL_TIME')),
            'PRODUCT_COMMENTS_AUTO_APPROVE' => Tools::getValue('PRODUCT_COMMENTS_AUTO_APPROVE', Configuration::get('PRODUCT_COMMENTS_AUTO_APPROVE')),
        );
    }

    public function getCriterionFieldsValues($id = 0)
    {
        $criterion = new ProductCommentCriterion($id);

        return array(
                    'name' => $criterion->name,
                    'id_product_comment_criterion_type' => $criterion->id_product_comment_criterion_type,
                    'active' => $criterion->active,
                    'id_product_comment_criterion' => $criterion->id,
                );
    }

    public function getStandardFieldList()
    {
        return array(
            'id_product_comment' => array(
                'title' => $this->trans('ID', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'title' => array(
                'title' => $this->trans('Review title', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'content' => array(
                'title' => $this->trans('Review', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'grade' => array(
                'title' => $this->trans('Rating', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
                'suffix' => '/5',
            ),
            'customer_name' => array(
                'title' => $this->trans('Author', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'name' => array(
                'title' => $this->trans('Product', array(), 'Modules.Productcomments.Admin'),
                'type' => 'text',
            ),
            'date_add' => array(
                'title' => $this->trans('Time of publication', array(), 'Modules.Productcomments.Admin'),
                'type' => 'date',
            ),
        );
    }

    public function renderCriterionForm($id_criterion = 0)
    {
        $types = ProductCommentCriterion::getTypes();
        $query = array();
        foreach ($types as $key => $value) {
            $query[] = array(
                    'id' => $key,
                    'label' => $value,
                );
        }

        $criterion = new ProductCommentCriterion((int) $id_criterion);
        $selected_categories = $criterion->getCategories();

        $product_table_values = Product::getSimpleProducts($this->context->language->id);
        $selected_products = $criterion->getProducts();
        foreach ($product_table_values as $key => $product) {
            if (false !== array_search($product['id_product'], $selected_products)) {
                $product_table_values[$key]['selected'] = 1;
            }
        }

        if (version_compare(_PS_VERSION_, '1.6', '<')) {
            $field_category_tree = array(
                                    'type' => 'categories_select',
                                    'name' => 'categoryBox',
                                    'label' => $this->trans('Criterion will be restricted to the following categories', array(), 'Modules.Productcomments.Setting'),
                                    'category_tree' => $this->initCategoriesAssociation(null, $id_criterion),
                                );
        } else {
            $field_category_tree = array(
                            'type' => 'categories',
                            'label' => $this->trans('Criterion will be restricted to the following categories', array(), 'Modules.Productcomments.Setting'),
                            'name' => 'categoryBox',
                            'desc' => $this->trans('Mark the boxes of categories to which this criterion applies.', array(), 'Modules.Productcomments.Setting'),
                            'tree' => array(
                                'use_search' => false,
                                'id' => 'categoryBox',
                                'use_checkbox' => true,
                                'selected_categories' => $selected_categories,
                            ),
                            //retro compat 1.5 for category tree
                            'values' => array(
                                'trads' => array(
                                    'Root' => Category::getTopCategory(),
                                    'selected' => $this->trans('Selected', array(), 'Modules.Productcomments.Setting'),
                                    'Collapse All' => $this->trans('Collapse All', array(), 'Modules.Productcomments.Setting'),
                                    'Expand All' => $this->trans('Expand All', array(), 'Modules.Productcomments.Setting'),
                                    'Check All' => $this->trans('Check All', array(), 'Modules.Productcomments.Setting'),
                                    'Uncheck All' => $this->trans('Uncheck All', array(), 'Modules.Productcomments.Setting'),
                                ),
                                'selected_cat' => $selected_categories,
                                'input_name' => 'categoryBox[]',
                                'use_radio' => false,
                                'use_search' => false,
                                'disabled_categories' => array(),
                                'top_category' => Category::getTopCategory(),
                                'use_context' => true,
                            ),
                        );
        }

        $fields_form_1 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Add new criterion', array(), 'Modules.Productcomments.Setting'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'hidden',
                        'name' => 'id_product_comment_criterion',
                    ),
                    array(
                        'type' => 'text',
                        'lang' => true,
                        'label' => $this->trans('Criterion name', array(), 'Modules.Productcomments.Setting'),
                        'name' => 'name',
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'id_product_comment_criterion_type',
                        'label' => $this->trans('Application scope of the criterion', array(), 'Modules.Productcomments.Setting'),
                        'options' => array(
                                        'query' => $query,
                                        'id' => 'id',
                                        'name' => 'label',
                                    ),
                    ),
                    $field_category_tree,
                    array(
                        'type' => 'products',
                        'label' => $this->trans('The criterion will be restricted to the following products', array(), 'Modules.Productcomments.Setting'),
                        'name' => 'ids_product',
                        'values' => $product_table_values,
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true, //retro compat 1.5
                        'label' => $this->trans('Active', array(), 'Modules.Productcomments.Setting'),
                        'name' => 'active',
                        'values' => array(
                                        array(
                                            'id' => 'active_on',
                                            'value' => 1,
                                            'label' => $this->trans('Enabled', array(), 'Modules.Productcomments.Setting'),
                                        ),
                                        array(
                                            'id' => 'active_off',
                                            'value' => 0,
                                            'label' => $this->trans('Disabled', array(), 'Modules.Productcomments.Setting'),
                                        ),
                                    ),
                    ),
                ),
            'submit' => array(
                'title' => $this->trans('Save', array(), 'Modules.Productcomments.Setting'),
                'class' => 'btn btn-default pull-right',
                'name' => 'submitEditCriterion',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->name;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitEditCriterion';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getCriterionFieldsValues($id_criterion),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields_form_1));
    }

    private function _checkDeleteComment()
    {
        $action = Tools::getValue('delete_action');
        if (empty($action) === false) {
            $product_comments = Tools::getValue('delete_id_product_comment');

            if (count($product_comments)) {
                require_once dirname(__FILE__).'/ProductComment.php';
                if ($action == 'delete') {
                    foreach ($product_comments as $id_product_comment) {
                        if (!$id_product_comment) {
                            continue;
                        }
                        $comment = new ProductComment((int) $id_product_comment);
                        $comment->delete();
                        ProductComment::deleteGrades((int) $id_product_comment);
                    }
                }
            }
        }
    }

    private function _setFilters()
    {
        $this->_filters = array(
                            'page' => (string) Tools::getValue('submitFilter'.$this->name),
                            'pagination' => (string) Tools::getValue($this->name.'_pagination'),
                            'filter_id' => (string) Tools::getValue($this->name.'Filter_id_product_comment'),
                            'filter_content' => (string) Tools::getValue($this->name.'Filter_content'),
                            'filter_customer_name' => (string) Tools::getValue($this->name.'Filter_customer_name'),
                            'filter_grade' => (string) Tools::getValue($this->name.'Filter_grade'),
                            'filter_name' => (string) Tools::getValue($this->name.'Filter_name'),
                            'filter_date_add' => (string) Tools::getValue($this->name.'Filter_date_add'),
                        );
    }

    public function displayApproveLink($token, $id, $name = null)
    {
        $this->smarty->assign(array(
            'href' => $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name.'&module_name='.$this->name.'&approveComment='.$id,
            'action' => $this->trans('Approve'),
        ));

        return $this->display(__FILE__, 'views/templates/admin/list_action_approve.tpl');
    }

    public function displayNoabuseLink($token, $id, $name = null)
    {
        $this->smarty->assign(array(
            'href' => $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name.'&module_name='.$this->name.'&noabuseComment='.$id,
            'action' => $this->trans('Not abusive', array(), 'Modules.Productcomments.Setting'),
        ));

        return $this->display(__FILE__, 'views/templates/admin/list_action_noabuse.tpl');
    }

    public function hookDisplayFooterProduct2($params)
    {
        require_once dirname(__FILE__).'/ProductComment.php';
        require_once dirname(__FILE__).'/ProductCommentCriterion.php';

        $average = ProductComment::getAverageGrade((int) Tools::getValue('id_product'));

        $this->context->smarty->assign(array(
                                            'allow_guests' => (int) Configuration::get('PRODUCT_COMMENTS_ALLOW_GUESTS'),
                                            'comments' => ProductComment::getByProduct((int) (Tools::getValue('id_product'))),
                                            'criterions' => ProductCommentCriterion::getByProduct((int) (Tools::getValue('id_product')), $this->context->language->id),
                                            'averageTotal' => round($average['grade']),
                                            'nbComments' => (int) (ProductComment::getCommentNumber((int) (Tools::getValue('id_product')))),
                                       ));
        return $this->display(__FILE__, '/tab.tpl');
    }

    public function hookDisplayProductListReviews($params)
    //public function hookDisplayReassurance($params)
    {
        $id_product = (int) $params['product']['id_product'];
        if (!$this->isCached('productcomments_reviews.tpl', $this->getCacheId($id_product))) {
            require_once dirname(__FILE__).'/ProductComment.php';
            $average = ProductComment::getAverageGrade($id_product);
            $this->smarty->assign(array(
                'product' => $params['product'],
                'averageTotal' => round($average['grade']),
                'ratings' => ProductComment::getRatings($id_product),
                'nbComments' => (int) ProductComment::getCommentNumber($id_product),
            ));
        }

        return $this->display(__FILE__, 'productcomments_reviews.tpl', $this->getCacheId($id_product));
    }

    public function hookDisplayRightColumnProduct($params)
    //public function hookDisplayReassurance()
    {
        require_once dirname(__FILE__).'/ProductComment.php';
        require_once dirname(__FILE__).'/ProductCommentCriterion.php';

        $id_guest = (!$id_customer = (int) $this->context->cookie->id_customer) ? (int) $this->context->cookie->id_guest : false;
        $customerComment = ProductComment::getByCustomer((int) (Tools::getValue('id_product')), (int) $this->context->cookie->id_customer, true, (int) $id_guest);

        $average = ProductComment::getAverageGrade((int) Tools::getValue('id_product'));
        $product = $this->context->controller->getProduct();
        $image = Product::getCover((int) Tools::getValue('id_product'));
        $cover_image = $this->context->link->getImageLink($product->link_rewrite, $image['id_image'], 'medium_default');

        $this->context->smarty->assign(array(
            'id_product_comment_form' => (int) Tools::getValue('id_product'),
            'product' => $product,
            'secure_key' => $this->secure_key,
            'logged' => $this->context->customer->isLogged(true),
            'allow_guests' => (int) Configuration::get('PRODUCT_COMMENTS_ALLOW_GUESTS'),
            'productcomment_cover' => (int) Tools::getValue('id_product').'-'.(int) $image['id_image'], // retro compat
            'productcomment_cover_image' => $cover_image,
            'mediumSize' => Image::getSize(ImageType::getFormatedName('medium')),
            'criterions' => ProductCommentCriterion::getByProduct((int) Tools::getValue('id_product'), $this->context->language->id),
            'action_url' => '',
            'averageTotal' => round($average['grade']),
            'ratings' => ProductComment::getRatings((int) Tools::getValue('id_product')),
            'too_early' => ($customerComment && (strtotime($customerComment['date_add']) + Configuration::get('PRODUCT_COMMENTS_MINIMAL_TIME')) > time()),
            'nbComments' => (int) (ProductComment::getCommentNumber((int) Tools::getValue('id_product'))),
       ));

        return $this->display(__FILE__, '/productcomments-extra.tpl');
    }

    public function hookDisplayLeftColumnProduct($params)
    {
        return $this->hookDisplayRightColumnProduct($params);
    }

    //public function hookDisplayReassurance($params)hookDisplayFooterProduct
    public function hookDisplayFooterProduct($params)
    {
        require_once dirname(__FILE__).'/ProductComment.php';
        require_once dirname(__FILE__).'/ProductCommentCriterion.php';

        $site_url = Tools::getShopProtocol().Tools::getShopDomain().__PS_BASE_URI__;

        /*$this->context->controller->addJS(array(
            $site_url.'/modules/productcomments/js/jquery.rating.pack.js',
            $site_url.'/modules/productcomments/js/jquery.textareaCounter.plugin.js',
            $site_url.'/modules/productcomments/js/productcomments.js'
        ));
*/
        $id_guest = (!$id_customer = (int) $this->context->cookie->id_customer) ? (int) $this->context->cookie->id_guest : false;
        $customerComment = ProductComment::getByCustomer((int) (Tools::getValue('id_product')), (int) $this->context->cookie->id_customer, true, (int) $id_guest);

        $averages = ProductComment::getAveragesByProduct((int) Tools::getValue('id_product'), $this->context->language->id);
        $averageTotal = 0;
        foreach ($averages as $average) {
            $averageTotal += (float) ($average);
        }
        $averageTotal = count($averages) ? ($averageTotal / count($averages)) : 0;

        $product = $this->context->controller->getProduct();

        $image = Product::getCover((int) Tools::getValue('id_product'));
        $cover_image = $this->context->link->getImageLink($product->link_rewrite, $image['id_image'], 'medium_default');

        $this->context->smarty->assign(array(
            'site_url' => $site_url,
            'logged' => $this->context->customer->isLogged(true),
            'action_url' => '',
            'product' => $product,
            'comments' => ProductComment::getByProduct((int) Tools::getValue('id_product'), 1, null, $this->context->cookie->id_customer),
            'criterions' => ProductCommentCriterion::getByProduct((int) Tools::getValue('id_product'), $this->context->language->id),
            'averages' => $averages,
            'product_comment_path' => $this->_path,
            'averageTotal' => $averageTotal,
            'allow_guests' => (int) Configuration::get('PRODUCT_COMMENTS_ALLOW_GUESTS'),
            'too_early' => ($customerComment && (strtotime($customerComment['date_add']) + Configuration::get('PRODUCT_COMMENTS_MINIMAL_TIME')) > time()),
            'delay' => Configuration::get('PRODUCT_COMMENTS_MINIMAL_TIME'),
            'id_product_comment_form' => (int) Tools::getValue('id_product'),
            'secure_key' => $this->secure_key,
            'productcomment_cover' => (int) Tools::getValue('id_product').'-'.(int) $image['id_image'],
            'productcomment_cover_image' => $cover_image,
            'mediumSize' => Image::getSize(ImageType::getFormatedName('medium')),
            'nbComments' => (int) ProductComment::getCommentNumber((int) Tools::getValue('id_product')),
            'productcomments_controller_url' => $this->context->link->getModuleLink('productcomments', array()),
            'productcomments_url_rewriting_activated' => Configuration::get('PS_REWRITING_SETTINGS', 0),
            'moderation_active' => (int) Configuration::get('PRODUCT_COMMENTS_MODERATE'),
       ));

        $this->pagination((int) ProductComment::getCommentNumber((int) Tools::getValue('id_product')));

        return $this->display(__FILE__, '/productcomments.tpl');
    }

    public function hookHeader()
    {
        $this->context->controller->addCSS($this->_path.'css/font-awesome.min.css', 'all');
        $this->context->controller->addCSS($this->_path.'css/productcomments.css', 'all');

        $this->page_name = Dispatcher::getInstance()->getController();
        if (in_array($this->page_name, array('product', 'productscomparison'))) {
            $this->context->controller->addJS($this->_path.'js/jquery.rating.pack.js');
            if (in_array($this->page_name, array('productscomparison'))) {
                $this->context->controller->addjqueryPlugin('cluetip');
                $this->context->controller->addJS($this->_path.'js/products-comparison.js');
            }
        }
    }

    public function hookExtraProductComparison($params)
    {
        require_once dirname(__FILE__).'/ProductComment.php';
        require_once dirname(__FILE__).'/ProductCommentCriterion.php';

        $list_grades = array();
        $list_product_grades = array();
        $list_product_average = array();
        $list_product_comment = array();

        foreach ($params['list_ids_product'] as $id_product) {
            $id_product = (int) $id_product;
            $grades = ProductComment::getAveragesByProduct($id_product, $this->context->language->id);
            $criterions = ProductCommentCriterion::getByProduct($id_product, $this->context->language->id);
            $grade_total = 0;
            if (count($grades) > 0) {
                foreach ($criterions as $criterion) {
                    if (isset($grades[$criterion['id_product_comment_criterion']])) {
                        $list_product_grades[$criterion['id_product_comment_criterion']][$id_product] = $grades[$criterion['id_product_comment_criterion']];
                        $grade_total += (float) ($grades[$criterion['id_product_comment_criterion']]);
                    } else {
                        $list_product_grades[$criterion['id_product_comment_criterion']][$id_product] = 0;
                    }

                    if (!array_key_exists($criterion['id_product_comment_criterion'], $list_grades)) {
                        $list_grades[$criterion['id_product_comment_criterion']] = $criterion['name'];
                    }
                }

                $list_product_average[$id_product] = $grade_total / count($criterions);
                $list_product_comment[$id_product] = ProductComment::getByProduct($id_product, 0, 3);
            }
        }

        if (count($list_grades) < 1) {
            return false;
        }

        $this->context->smarty->assign(array(
            'grades' => $list_grades,
            'product_grades' => $list_product_grades,
            'list_ids_product' => $params['list_ids_product'],
            'list_product_average' => $list_product_average,
            'product_comments' => $list_product_comment,
        ));

        return $this->display(__FILE__, '/products-comparison.tpl');
    }

    public function initCategoriesAssociation($id_root = null, $id_criterion = 0)
    {
        if (is_null($id_root)) {
            $id_root = Configuration::get('PS_ROOT_CATEGORY');
        }
        $id_shop = (int) Tools::getValue('id_shop');
        $shop = new Shop($id_shop);
        if ($id_criterion == 0) {
            $selected_cat = array();
        } else {
            $pdc_object = new ProductCommentCriterion($id_criterion);
            $selected_cat = $pdc_object->getCategories();
        }

        if (Shop::getContext() == Shop::CONTEXT_SHOP && Tools::isSubmit('id_shop')) {
            $root_category = new Category($shop->id_category);
        } else {
            $root_category = new Category($id_root);
        }
        $root_category = array('id_category' => $root_category->id, 'name' => $root_category->name[$this->context->language->id]);

        $helper = new Helper();

        return $helper->renderCategoryTree($root_category, $selected_cat, 'categoryBox', false, true);
    }

    protected function pagination($total_products = null)
    {
        $context = Context::getContext();
        
        // Retrieve the default number of products per page and the other available selections
        $default_products_per_page = max(1, (int)Configuration::get('PS_PRODUCTS_PER_PAGE'));
        $n_array = array($default_products_per_page, $default_products_per_page * 2, $default_products_per_page * 5);

        if ((int)Tools::getValue('n') && (int)$total_products > 0) {
            $n_array[] = $total_products;
        }
        // Retrieve the current number of products per page (either the default, the GET parameter or the one in the cookie)
        $n = $default_products_per_page;
        if (isset($context->cookie->nb_item_per_page) && in_array($context->cookie->nb_item_per_page, $n_array)) {
            $n = (int)$context->cookie->nb_item_per_page;
        }

        if ((int)Tools::getValue('n') && in_array((int)Tools::getValue('n'), $n_array)) {
            $n = (int)Tools::getValue('n');
        }

        // Retrieve the page number (either the GET parameter or the first page)
        $p = (int)Tools::getValue('p', 1);
        // If the parameter is not correct then redirect (do not merge with the previous line, the redirect is required in order to avoid duplicate content)
        if (!is_numeric($p) || $p < 1) {
            Tools::redirect($context->link->getPaginationLink(false, false, $n, false, 1, false));
        }

        // Remove the page parameter in order to get a clean URL for the pagination template
        $current_url = preg_replace('/(?:(\?)|&amp;)p=\d+/', '$1', Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']));

        if ($n != $default_products_per_page || isset($context->cookie->nb_item_per_page)) {
            $context->cookie->nb_item_per_page = $n;
        }

        $pages_nb = ceil($total_products / (int)$n);
        if ($p > $pages_nb && $total_products != 0) {
            Tools::redirect($context->link->getPaginationLink(false, false, $n, false, $pages_nb, false));
        }

        $range = 2; /* how many pages around page selected */
        $start = (int)($p - $range);
        if ($start < 1) {
            $start = 1;
        }

        $stop = (int)($p + $range);
        if ($stop > $pages_nb) {
            $stop = (int)$pages_nb;
        }

        $this->context->smarty->assign(array(
            'nb_products'       => $total_products,
            'products_per_page' => $n,
            'pages_nb'          => $pages_nb,
            'p'                 => $p,
            'n'                 => $n,
            'nArray'            => $n_array,
            'range'             => $range,
            'start'             => $start,
            'stop'              => $stop,
            'current_url'       => $current_url,
        ));
    }
}
