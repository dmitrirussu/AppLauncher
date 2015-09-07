<?php
/**
 * Created by Dumitru Russu.
 * Date: 17.05.2014
 * Time: 16:17
 * ${NAMESPACE}${NAME} 
 */

namespace AppLauncher;

use AppLauncher\Exceptions\RegisterAppFacadeException;
use AppLauncher\Interfaces\AppControllerInterface;
use AppLauncher\Action\Exceptions\RootingException;
use AppLauncher\Action\Request;
use AppLauncher\Action\Response;
use AppLauncher\Action\Rooting;
use AppLauncher\Secure\User;

class RegisterAppFacade {

	private $request;
	private $rooting;

	private $requestInfo;
	/**
	 * @var AppControllerInterface
	 */
	private $controller;
	private $action;

	/**
	 * @var Response
	 */
	private $response;
	private $tplDirectory;

	public function __construct(Request $request, Rooting $rooting) {
		$this->request = $request;
		$this->rooting = $rooting;
		try {
			$this->requestInfo = $this->rooting->getInfoByUrl($this->request->get('page', 'char', 'default'));

			$this->controller = $this->requestInfo['class'];
			$this->action = $this->requestInfo['action'];
		}
		catch(RootingException $e) {

			if (Launch::isDevEnvironment()) {
				Request::session()->setVar('exception', $e);
				Request::redirect('Error/Default');
			}
			else {
				Request::redirect('Error/Page404');
			}
		}
		catch(\Exception $e) {

			if ( Launch::isDevEnvironment() ) {

				Request::session()->setVar('exception', $e);
				Request::redirect('Error/Default');
			}
			else {

				Request::redirect('Error/Page404');
			}
		}
	}

	public function display() {
		try {

			if ( $this->controller ) {
				$reflectionControllerClass = new \ReflectionClass($this->controller);
				$controllerProjectName = str_replace('\\Controllers', '', $reflectionControllerClass->getNamespaceName());
				$isProjectApp = (!in_array($controllerProjectName, HTML::instance()->getApps()) ? true : false);



				$tplDirectory = explode('\\', strtolower(str_replace('Controller', '', $this->controller)));
				$this->tplDirectory = strtolower(end($tplDirectory));

				//Set Is Project App
				HTML::instance()
					->setIsProjectApp($isProjectApp)
					->setAppName($controllerProjectName)
					->setAppPageName($this->tplDirectory);

				//check if class has method
				if ( !$reflectionControllerClass->hasMethod($this->action) ) {

					throw new RegisterAppFacadeException('Controller ['. $this->controller
						.'], Action method ['. $this->action
						.'] does not exist');
				}

				$this->controller = new $this->controller();

				if ( !User::isLogged() && $this->controller->isSecured() ) {

					Request::redirect(Rooting::url('DefaultController->defaultAction'));
				}

				/**
				 * @var $result Response
				 */
				$this->response = $this->controller->{$this->action}();

				if( empty($this->response)) {

					throw new RegisterAppFacadeException('Missing Action Response AC-NAME: ' . $this->action);
				}

				if ( is_array($this->response) ) {

					$this->response = new Response($this->response);
				}
				
				$this->controller->assign('errors', $this->controller->getErrorMessages());
				$this->controller->assign('successMessages', $this->controller->getSuccessMessages());

				switch($this->response->getType()) {
					case 'h':
					case 'html': {

						//unset errors
						$this->controller->getRequest()->session()->unsetVar('errors');
						$this->controller->getRequest()->session()->unsetVar('successMessage');

						$this->displayLayoutTemplates($isProjectApp, $controllerProjectName);

						break;
					}
					case 'hb':
					case 'html_block': {

						$this->displayHTMLBlock();
						break;
					}
					case 't':
					case 'text': {
						$this->displayText();
						break;
					}
					case 'x':
					case 'xml': {
						$this->displayXml();
						break;
					}
					case 'j':
					case 'json': {

						$this->displayJsonEncodedString();
						break;
					}
					case 'f':
					case 'file': {

						$this->displayFile();
						break;
					}
					case 'd':
					case 'download' : {

						$this->forceDownloadFile($controllerProjectName);

						break;
					}
					case 'r':
					case 'redirect': {

						$url = $this->response->getDisplay() || $this->response->getUrl();

						if ( !filter_var($this->response->getDisplay(), FILTER_VALIDATE_URL) || !filter_var($this->response->getUrl(), FILTER_VALIDATE_URL) ) {

							if ( $this->response->getDisplay() ) {
								$url = Rooting::url($this->response->getDisplay());
							}
							else {

								$url = Rooting::url($this->response->getUrl());
							}
						}

						Request::redirect($url, $this->response->getIsHttps(), $this->response->getIsLocale());

						break;
					}
					default : {

						$this->displayHTMLBlock();
						break;
					}
				}
			}
		}
		catch(\Exception $e) {
			if ( Launch::isDevEnvironment() ) {

				Request::session()->setVar('exception', serialize($e));
				Request::redirect('/Error/Default');
			}
			else {

				Request::redirect('Error/Page404');
			}
		}
	}

	private function forceDownloadFile($controllerProjectName) {

		$filePath = $this->response->getFileName();
		if ( !file_exists($this->response->getFileName()) ) {
			$filePath = PATH_PUBLIC . $controllerProjectName . DIRECTORY_SEPARATOR . $this->response->getFileName();
		}

		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Cache-Control: public");
		header("Content-Description: File Transfer");
		header("Content-type: application/octet-stream");
		header("Content-Disposition: attachment; filename=\"".basename($filePath)."\"");
		header("Content-Transfer-Encoding: binary");
		header("Content-Length: ".filesize($filePath));
		@readfile($filePath);
		exit;
	}

	/**
	 * Display Layout Template
	 * @param $isProjectApp
	 * @param $controllerProjectName
	 */
	private function displayLayoutTemplates($isProjectApp, $controllerProjectName) {
		$this->controller->assign('cssFiles', Scripts::instance()->getCssFiles());
		$this->controller->assign('javaScriptFiles', Scripts::instance()->getScriptsJs());
		$this->controller->assign('tplName', $this->response->getDisplay());
		$this->controller->assign('breadCrumbs', $this->controller->getBreadCrumbs());

		$this->controller->assign('globalVars', $this->controller->getAssignedVars());

		if ( $this->controller->getAssignedVars() ) {

			foreach($this->controller->getAssignedVars() AS $key => $value) {

				$$key = $value;
			}
		}

		ob_start();

		if ( $isProjectApp ) {

			$includeBaseAppTPL = PATH_APP . $controllerProjectName . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR. 'index' . HTML::TPL_EXT;
			$includeBaseLibsTPL = PATH_LIBS . $controllerProjectName . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR. 'index' . HTML::TPL_EXT;

			if ( file_exists($includeBaseAppTPL) ) {

				require_once $includeBaseAppTPL;
			}
			elseif (file_exists($includeBaseLibsTPL)) {

				require_once $includeBaseLibsTPL;
			}
		}
		else {

			foreach(HTML::instance()->getApps() AS $appName) {

				$includeBaseTPL = PATH_APP . $appName . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR. 'index' . HTML::TPL_EXT;

				if ( file_exists($includeBaseTPL) ) {

					require_once $includeBaseTPL;
					break;
				}
			}
		}

		ob_end_flush();
	}

	private function displayText() {
		return print ($this->response->getDisplay());
	}

	private function displayXml() {
		header("Content-type: text/xml; charset=utf-8");
		return print ($this->response->getDisplay() ? $this->response->getDisplay() : $this->response->getFileName());
	}

	/**
	 * Display HTML Block
	 */
	private function displayHTMLBlock() {

		HTML::block($this->tplDirectory . DIRECTORY_SEPARATOR . $this->response->getDisplay(),
			$this->controller->getAssignedVars());
	}

	/**
	 * Display Json encode String
	 */
	private function displayJsonEncodedString() {

		ob_start();
		header('Content-Type: application/json');
		if ( is_array($this->response->getDisplay()) ) {
			$jsonString = json_encode($this->response->getDisplay());
			echo($jsonString);
		}
		elseif ( is_array($this->response->getData()) ) {
			$jsonString = json_encode($this->response->getData());
			echo($jsonString);
		}
		else {
			echo('Missing Array Data');
		}
		ob_end_flush();
		exit;
	}

	/**
	 * Display File
	 */
	private function displayFile() {

		ob_start();
		// Define HTTP header fields.
		header('Content-Type: ' . $this->response->getContentType());
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Content-Length: '.filesize($this->response->getFileName()));
		header('Content-Disposition: inline; filename='.basename($this->response->getFileName()));
		header('Content-Transfer-Encoding: binary');

		print (file_get_contents($this->response->getFileName()));
		ob_end_flush();
		exit;
	}
}

