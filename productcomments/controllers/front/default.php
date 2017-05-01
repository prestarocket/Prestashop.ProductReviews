<?php

include_once(dirname(__FILE__).'/../../productcomments.php');
include_once(dirname(__FILE__).'/../../ProductComment.php');
include_once(dirname(__FILE__).'/../../ProductCommentCriterion.php');

class ProductCommentsDefaultModuleFrontController extends ModuleFrontController
{
	public function __construct()
	{
		parent::__construct();
		$this->context = Context::getContext();
	}

	public function initContent()
	{
		parent::initContent();

		if (Tools::isSubmit('action')) {
			switch (Tools::getValue('action')) {
				case 'add_comment':
					$this->ajaxProcessAddComment();
					break;

				case 'report_abuse':
					$this->ajaxProcessReportAbuse();
					break;

				case 'comment_is_usefull':
					$this->ajaxProcessCommentIsUsefull();
					break;

				default:
					break;
			}
		}
	}

	protected function ajaxProcessAddComment()
	{
		$module_instance = new ProductComments();

		$result = true;
		$id_guest = 0;
		$id_customer = $this->context->customer->id;
		if (!$id_customer) {
			$id_guest = $this->context->cookie->id_guest;
		}

		$is_auto_appreove = Configuration::get('PRODUCT_COMMENTS_AUTO_APPROVE');
		$html = '';

		$errors = array();
		if (!Validate::isInt(Tools::getValue('id_product'))) {
			$errors[] = $module_instance->l('Product ID is incorrect', 'default');
		}
		if (!Tools::getValue('title') || !Validate::isGenericName(Tools::getValue('title'))) {
			$errors[] = $module_instance->l('Title is incorrect', 'default');
		}
		if (!Tools::getValue('content') || !Validate::isMessage(Tools::getValue('content'))) {
			$errors[] = $module_instance->l('Comment is incorrect', 'default');
		}
		if (!$id_customer && (!Tools::isSubmit('customer_name') || !Tools::getValue('customer_name') || !Validate::isGenericName(Tools::getValue('customer_name')))) {
			$errors[] = $module_instance->l('Customer name is incorrect', 'default');
		}
		if (!$this->context->customer->id && !Configuration::get('PRODUCT_COMMENTS_ALLOW_GUESTS')) {
			$errors[] = $module_instance->l('You must be login to send a comment', 'default');
		}
		if (!count(Tools::getValue('criterion'))) {
			$errors[] = $module_instance->l('You must give a rating', 'default');
		}

		$product = new Product(Tools::getValue('id_product'));
		if (!$product->id) {
			$errors[] = $module_instance->l('Product not found', 'default');
		}

		$comment = new ProductComment();
		if (!count($errors)) {
			$customer_comment = ProductComment::getByCustomer(Tools::getValue('id_product'), $id_customer, true, $id_guest);
			if (!$customer_comment || ($customer_comment && (strtotime($customer_comment['date_add']) + (int)Configuration::get('PRODUCT_COMMENTS_MINIMAL_TIME')) < time())) {
				$comment->content = strip_tags(Tools::getValue('content'));
				$comment->id_product = (int)Tools::getValue('id_product');
				$comment->id_customer = (int)$id_customer;
				$comment->id_guest = $id_guest;
				$comment->customer_name = Tools::getValue('customer_name');
				if (!$comment->customer_name) {
					$comment->customer_name = pSQL($this->context->customer->firstname.' '.$this->context->customer->lastname);
				}
				$comment->title = Tools::getValue('title');
				$comment->grade = 0;
				$comment->validate = 0;
				$comment->save();

				$grade_sum = 0;
				foreach (Tools::getValue('criterion') as $id_product_comment_criterion => $grade) {
					$grade_sum += $grade;
					$product_comment_criterion = new ProductCommentCriterion($id_product_comment_criterion);
					if ($product_comment_criterion->id) {
						$product_comment_criterion->addGrade($comment->id, $grade);
					}
				}

				if (count(Tools::getValue('criterion')) >= 1) {
					$comment->grade = $grade_sum / count(Tools::getValue('criterion'));
					$comment->save();
				}

				$result = true;
				Tools::clearCache(Context::getContext()->smarty, $this->getTemplatePath('productcomments-reviews.tpl'));
			} else {
				$result = false;
				$errors[] = $module_instance->l('Please wait before posting another comment', 'default').' '.Configuration::get('PRODUCT_COMMENTS_MINIMAL_TIME').' '.$module_instance->l('seconds before posting a new comment', 'default');
			}
		}
		else {
			$result = false;
		}

		$site_url = Tools::getShopProtocol().Tools::getShopDomain() . __PS_BASE_URI__;
		if ($is_auto_appreove == 1 && $result) {
			Db::getInstance()->execute('
				UPDATE `'._DB_PREFIX_.'product_comment`
				SET `validate` = 1
				WHERE `id_product_comment` = '.(int)$comment->id.'
			');

			$html .= '<div class="comment clearfix">';
			$html .= 	'<span class="icircle"><img class="iavatar" title="" alt="" src="'.$site_url.'/modules/productcomments/img/user.png" /></span>';
			$html .= 	'<div class="istar_content clearfix">';
			$html .= 		'<span class="iauthor">'.$comment->customer_name.'</span>';
			$html .= 		'<div class="imetadata iright">';
			$html .= 			'<div class="idatae">'.date('mm/dd/yyyy').'</div>';
			$html .= 			'<div class="irating">';
			for ($i = 1; $i < 6; $i ++) { 
				if ($i <= $comment->grade) {
					$html .= '<i class="fa fa-star" aria-hidden="true" style="color:#FFCC00;"></i>';
				} else {
					$html .= '<i class="fa fa-star" aria-hidden="true"></i>';
				}
			}
			$html .= 			'</div>';
			$html .= 		'</div>';
			$html .= 	'<div class="itext">';
			$html .= 		'<span class="title_block">'.$comment->title.'</span>';
			$html .=		'<p>'.$comment->content.'</p>';
			$html .= 		'<div><div class="itext-bottom">';
			$html .= 			'<span class="usefulness_btn" data-is-usefull="1" data-id-product-comment="'.$comment->id.'">'.$this->l('Like').'</span>';
			$html .= 			'<span class="usefulness_btn" data-is-usefull="0" data-id-product-comment="'.$comment->id.'">'.$this->l('Dislike').'</span>';
			$html .= 			'<span class="report_btn" data-id-product-comment="'.$comment->id.'">'.$this->l('Report').'</span>';
			$html .= 		'</div></div>';
			$html .= 	'</div>';
			$html .= '</div>';
			$html .= 	'</div>';
		}

		die(Tools::jsonEncode(array(
			'result' => $result,
			'errors' => $errors,
			'html' => $html,
		)));
	}

	protected function ajaxProcessReportAbuse()
	{
		if (!Tools::isSubmit('id_product_comment')) {
			die('0');
		}

		if (ProductComment::isAlreadyReport(Tools::getValue('id_product_comment'), $this->context->cookie->id_customer)) {
			die('0');
		}

		if (ProductComment::reportComment((int)Tools::getValue('id_product_comment'), $this->context->cookie->id_customer)) {
			die('1');
		}

		die('0');
	}

	protected function ajaxProcessCommentIsUsefull()
	{
		if (!Tools::isSubmit('id_product_comment') || !Tools::isSubmit('value')) {
			die('0');
		}

		if (ProductComment::isAlreadyUsefulness(Tools::getValue('id_product_comment'), $this->context->cookie->id_customer)) {
			die('0');
		}

		if (ProductComment::setCommentUsefulness((int)Tools::getValue('id_product_comment'), (bool)Tools::getValue('value'), $this->context->cookie->id_customer)) {
			die('1');
		}

		die('0');
	}
}
