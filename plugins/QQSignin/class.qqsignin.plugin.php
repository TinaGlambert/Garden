<?php if (!defined('APPLICATION')) exit();

$PluginInfo['QQSignin'] = array(
	'Name' => 'QQ登录',
	'Description' => '使用QQ登录',
	'Version' => '0.1',
	'RequiredApplications' => array('Vanilla' => '2.0.12a'),
	'RequiredTheme' => FALSE,
	'RequiredPlugins' => FALSE,
	'SettingsUrl' => '/dashboard/settings/qq',
	'SettingsPermission' => 'Garden.Settings.Manage',
	'MobileFriendly' => FALSE,
	'HasLocale' => FALSE,
	'RegisterPermissions' => FALSE,
	'Author' => "T.G.",
	'AuthorEmail' => 'farmer1992@gmail.com',
);

class QQSignInPlugin extends Gdn_Plugin {

	private static $BUTTON_IMG_URL = 'http://qzonestyle.gtimg.cn/qzone/vas/opensns/res/img/Connect_logo_3.png';
	private static $BUTTON_IMG_TITLE = '使用QQ登录';

	public function AuthenticationController_Render_Before($Sender, $Args) {
		if (isset($Sender->ChooserList)) {
			$Sender->ChooserList['qqsignin'] = 'QQ';
		}
		if (is_array($Sender->Data('AuthenticationConfigureList'))) {
			$List = $Sender->Data('AuthenticationConfigureList');
			$List['qqsignin'] = '/dashboard/settings/qq';
			$Sender->SetData('AuthenticationConfigureList', $List);
		}
	}

	public function EntryController_SignIn_Handler($Sender, $Args) {
		if (isset($Sender->Data['Methods'])) {
			if (!$this->IsConfigured())
				return;

			// Add the twitter method to the controller.
			$Method = array(
				'Name' => 'QQ',
				'SignInHtml' => $this->_GetButton(),
			);
			$Sender->Data['Methods'][] = $Method;
		}
	}


	public function Base_BeforeSignInButton_Handler($Sender, $Args) {
		if (!$this->IsConfigured())
			return;
			
		echo "\n".$this->_GetButton();
	}

	public function Base_ConnectData_Handler($Sender, $Args) {
		if (GetValue(0, $Args) != 'qqsignin'){
			return;
		}

		$client_id = C('Plugins.QQSignin.AppKey');
		$client_secret = C('Plugins.QQSignin.AppSecret');
		$redirect_uri = Url('/entry/connect/qqsignin', TRUE);

		$code = GetValue('code', $_GET);

		$Response = array();

		parse_str($this->get('https://graph.qq.com/oauth2.0/token',array(
			'grant_type' => 'authorization_code',
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'code' => $code,
			'redirect_uri' => $redirect_uri,

		)), $Response);

		$access_token = GetValue('access_token', $Response);

		$Response = $this->get('https://graph.qq.com/oauth2.0/me', array( 'access_token' => $access_token ));

		// copy from fucking qq sdk ... ridiculous
		$lpos = strpos($Response, "(");
		$rpos = strrpos($Response, ")");
		$Response  = substr($Response, $lpos + 1, $rpos - $lpos -1);
		$Response = json_decode($Response);

		$openid = GetValue('openid', $Response);
		$oauth_consumer_key = GetValue('client_id', $Response);

		$Response = $this->GetAndJsondecode('https://graph.qq.com/user/get_user_info', array(
			'openid' => $openid,
			'access_token' => $access_token,
			'oauth_consumer_key' => $oauth_consumer_key,
			'format' => 'json',
		));

		if(!$openid){
			$Sender->Form->AddError('Auth Failed');
			return;
		}

		$Form = $Sender->Form; //new Gdn_Form();
		$ID = $openid;
		$Form->SetFormValue('UniqueID', $ID);
		$Form->SetFormValue('Provider', 'QQ');
		$Form->SetFormValue('ProviderName', 'QQSignIn');
		$Form->SetFormValue('Name', htmlspecialchars(GetValue('nickname', $Response)));
		//$Form->SetFormValue('FullName', GetValue('name', $Profile));
		$Form->SetFormValue('Email', GetValue('nickname', $Response).'@via.qq');
		$Form->SetFormValue('Photo', GetValue('figureurl', $Response));
		$Sender->SetData('Verified', TRUE);
	}

	public function SettingsController_QQ_Create($Sender, $Args) {
		$Sender->Permission('Garden.Settings.Manage');
		if ($Sender->Form->IsPostBack()) {
			$Settings = array(
				'Plugins.QQSignin.AppKey' => $Sender->Form->GetFormValue('AppKey'),
				'Plugins.QQSignin.AppSecret' => $Sender->Form->GetFormValue('Secret')
			);

			SaveToConfig($Settings);
			$Sender->InformMessage(T("Your settings have been saved."));
		} else {
			$Sender->Form->SetFormValue('AppKey', C('Plugins.QQSignin.AppKey'));
			$Sender->Form->SetFormValue('Secret', C('Plugins.QQSignin.AppSecret'));
		}

		$Sender->AddSideMenu();
		$Sender->SetData('Title', T('QQ登录设置'));
		$Sender->Render('Settings', '', 'plugins/QQSignin');
	}

	// {{{ Draw button
	private function _GetButton() {      
		$ImgSrc = self::$BUTTON_IMG_URL;
		$ImgAlt = T(self::$BUTTON_IMG_TITLE);
		$SigninHref = $this->_AuthorizeHref();
		return "<a href=\"$SigninHref\" title=\"$ImgAlt\"><img src=\"$ImgSrc\" alt=\"$ImgAlt\" /></a>";
	}

	private function _AuthorizeHref($popup){
		$redirect_uri = Url('/entry/connect/qqsignin', TRUE);
		$client_id = C('Plugins.QQSignin.AppKey');

		return $this->build_url(
			'https://graph.qq.com/oauth2.0/authorize',
			array(
				'client_id' => $client_id,
				'response_type' => 'code',
				'redirect_uri' => $redirect_uri,
				'state' => 'login',
				// popup here
			)
		);
	}
	// }}}


	// {{{ http call utils ... 
	private function GetAndJsondecode($url, $arr){
		return @json_decode($this->get($url, $arr), true);
	}

	private function get($url, $arr){
		return file_get_contents($this->build_url($url, $arr));
	}

	private function build_url($url, $arr){
		return $url . '?' . http_build_query($arr);
	}
	// }}}

	public function IsConfigured() {
		$Result = C('Plugins.QQSignin.AppKey') && C('Plugins.QQSignin.AppSecret');
		return $Result;
	}
}
