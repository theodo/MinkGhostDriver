<?php
namespace Behat\Mink\Driver;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Session;
use WebDriver\Exception\UnknownError;
use WebDriver\Element;
use WebDriver\WebDriver;
use WebDriver\Key;

/**
 * PhantomJsDriver driver.
 *
 * @author Arnaud Dezandee <arnaudd@theodo.fr>
 */
class GhostDriver extends CoreDriver
{
    const PHANTOMJS_PAGE_SETTING_PREFIX       = 'phantomjs.page.settings.';
    const PHANTOMJS_PAGE_CUSTOMHEADERS_PREFIX = 'phantomjs.page.customHeaders.';

    /**
     * The current Mink session
     * @var \Behat\Mink\Session
     */
    private $session;

    /**
     * Whether the browser has been started
     * @var Boolean
     */
    private $started = false;

    /**
     * The WebDriver instance
     * @var WebDriver
     */
    private $webDriver;

    /**
     * @var array
     */
    private $capabilities = array();

    /**
     * @var array
     */
    private $settings = array();

    /**
     * @var array
     */
    private $customHeaders = array();

    /**
     * The WebDriverSession instance
     * @var \WebDriver\Session
     */
    private $wdSession;

    /**
     * The timeout configuration
     * @var array
     */
    private $timeouts = array();

    /**
     * Instantiates the driver.
     *
     * @param string $wdHost              The WebDriver host
     * @param array  $settings            Settings of web page for PhantomJS
     * @param array  $customHeaders       Custom Headers to be passed
     */
    public function __construct($wdHost = 'http://localhost:8910/wd/hub', $settings = null, $customHeaders = null)
    {
        $this->setSettings($settings);
        $this->setCustomHeaders($customHeaders);
        $this->setCapabilities();
        $this->setWebDriver(new WebDriver($wdHost));
    }

    /**
     * Sets default capabilities - called on construction.
     */
    public function setCapabilities()
    {
        $this->capabilities = self::getDefaultCapabilities();
    }

    /**
     * @param array $customHeaders
     */
    public function setCustomHeaders($customHeaders = null)
    {
        if (null == $customHeaders) {
            return null;
        }

        $prefixedHeaders = array();
        foreach ($customHeaders as $value) {
            $prefixedHeaders[self::PHANTOMJS_PAGE_CUSTOMHEADERS_PREFIX . $value['name']] = $value['value'];
        }

        $this->customHeaders = $prefixedHeaders;
    }

    /**
     * @param array $settings
     */
    public function setSettings($settings = null)
    {
        if (null == $settings) {
            return null;
        }

        $prefixedSettings = array();
        foreach ($settings as $key => $value) {
            $prefixedSettings[self::PHANTOMJS_PAGE_SETTING_PREFIX . $key] = $value;
        }

        $this->settings = $prefixedSettings;
    }

    /**
     * Sets the WebDriver instance
     *
     * @param WebDriver $webDriver An instance of the WebDriver class
     */
    public function setWebDriver(WebDriver $webDriver)
    {
        $this->webDriver = $webDriver;
    }

    /**
     * Gets the WebDriverSession instance
     *
     * @return \WebDriver\Session
     */
    public function getWebDriverSession()
    {
        return $this->wdSession;
    }

    /**
     * Returns the default capabilities
     *
     * @return array
     */
    public static function getDefaultCapabilities()
    {
        return array(
            'browserName'               => 'phantomjs',
            'version'                   => '1.9.7',
            'driverName'                => 'ghostdriver',
            'driverVersion'             => '1.1.0',
            'platform'                  => 'ANY',
            'javascriptEnabled'         => true,
            'takesScreenshot'           => true,
            'handlesAlerts'             => false,
            'databaseEnabled'           => false,
            'locationContextEnabled'    => false,
            'applicationCacheEnabled'   => false,
            'browserConnectionEnabled'  => false,
            'cssSelectorsEnabled'       => true,
            'webStorageEnabled'         => false,
            'rotatable'                 => false,
            'acceptSslCerts'            => false,
            'nativeEvents'              => true,
            'proxy' => array(
                'proxyType' => 'direct'
            ),
        );
    }

    /**
     * Makes sure that the Syn event library has been injected into the current page,
     * and return $this for a fluid interface,
     *
     *     $this->withSyn()->executeJsOnXpath($xpath, $script);
     *
     * @return $this
     */
    protected function withSyn()
    {
        $hasSyn = $this->wdSession->execute(array(
            'script' => 'return typeof window["Syn"]!=="undefined" && typeof window["Syn"].trigger!=="undefined"',
            'args'   => array()
        ));

        if (!$hasSyn) {
            $synJs = file_get_contents(__DIR__.'/GhostDriver/syn.js');
            $this->wdSession->execute(array(
                'script' => $synJs,
                'args'   => array()
            ));
        }

        return $this;
    }

    /**
     * Creates some options for key events
     *
     * @param string $char     the character or code
     * @param string $modifier one of 'shift', 'alt', 'ctrl' or 'meta'
     *
     * @return string a json encoded options array for Syn
     */
    protected static function charToOptions($char, $modifier = null)
    {
        $ord = ord($char);
        if (is_numeric($char)) {
            $ord = $char;
        }

        $options = array(
            'keyCode'  => $ord,
            'charCode' => $ord
        );

        if ($modifier) {
            $options[$modifier.'Key'] = 1;
        }

        return json_encode($options);
    }

    /**
     * Executes JS on a given element - pass in a js script string and {{ELEMENT}} will
     * be replaced with a reference to the result of the $xpath query
     *
     * @example $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.childNodes.length');
     *
     * @param string  $xpath  the xpath to search with
     * @param string  $script the script to execute
     * @param Boolean $sync   whether to run the script synchronously (default is TRUE)
     *
     * @return mixed
     */
    protected function executeJsOnXpath($xpath, $script, $sync = true)
    {
        $element   = $this->wdSession->element('xpath', $xpath);
        $elementID = $element->getID();
        $subscript = "arguments[0]";

        $script  = str_replace('{{ELEMENT}}', $subscript, $script);
        $execute = ($sync) ? 'execute' : 'execute_async';

        return $this->wdSession->$execute(array(
            'script' => $script,
            'args'   => array(array('ELEMENT' => $elementID))
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function setSession(Session $session)
    {
        $this->session = $session;
    }

    /**
     * {@inheritdoc}
     */
    public function start()
    {
        try {
            $this->wdSession = $this->webDriver->session(
                array_merge(
                    $this->capabilities,
                    $this->settings,
                    $this->customHeaders
                )
            );
            $this->applyTimeouts();
        } catch (\Exception $e) {
            throw new DriverException('Could not open connection: ' . $e->getMessage(), 0, $e);
        }

        if (!$this->wdSession) {
            throw new DriverException('Could not connect to a PhantomJs / GhostDriver server');
        }
        $this->started = true;
    }

    /**
     * Sets the timeouts to apply to the webdriver session
     *
     * @param array $timeouts The session timeout settings: Array of {script, implicit, page} => time in microsecconds
     *
     * @throws DriverException
     */
    public function setTimeouts($timeouts)
    {
        $this->timeouts = $timeouts;

        if ($this->isStarted()) {
            $this->applyTimeouts();
        }
    }

    /**
     * Applies timeouts to the current session
     */
    private function applyTimeouts()
    {
        try {
            foreach ($this->timeouts as $type => $param) {
                $this->wdSession->timeouts($type, $param);
            }
        } catch (UnknownError $e) {
            throw new DriverException('Error setting timeout: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted()
    {
        return $this->started;
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        if (!$this->wdSession) {
            throw new DriverException('Could not connect to a GhostDriver / WebDriver server');
        }

        $this->started = false;
        try {
            $this->wdSession->close();
        } catch (\Exception $e) {
            throw new DriverException('Could not close connection', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reset()
    {
        $this->wdSession->deleteAllCookies();
    }

    /**
     * {@inheritdoc}
     */
    public function visit($url)
    {
        $this->wdSession->open($url);
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentUrl()
    {
        return $this->wdSession->url();
    }

    /**
     * {@inheritdoc}
     */
    public function reload()
    {
        $this->wdSession->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function forward()
    {
        $this->wdSession->forward();
    }

    /**
     * {@inheritdoc}
     */
    public function back()
    {
        $this->wdSession->back();
    }

    /**
     * {@inheritdoc}
     */
    public function switchToWindow($name = null)
    {
        $this->wdSession->focusWindow($name ? $name : '');
    }

    /**
     * {@inheritdoc}
     */
    public function switchToIFrame($name = null)
    {
        $this->wdSession->frame(array('id' => $name));
    }

    /**
     * {@inheritdoc}
     */
    public function setCookie($name, $value = null)
    {
        if (null === $value) {
            $this->wdSession->deleteCookie($name);

            return;
        }

        $cookieArray = array(
            'name'   => $name,
            'value'  => (string) $value,
            'secure' => false, // thanks, chibimagic!
        );

        $this->wdSession->setCookie($cookieArray);
    }

    /**
     * {@inheritdoc}
     */
    public function getCookie($name)
    {
        $cookies = $this->wdSession->getAllCookies();
        foreach ($cookies as $cookie) {
            if ($cookie['name'] === $name) {
                return urldecode($cookie['value']);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getContent()
    {
        return $this->wdSession->source();
    }

    /**
     * {@inheritdoc}
     */
    public function getScreenshot()
    {
        return base64_decode($this->wdSession->screenshot());
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowNames()
    {
        return $this->wdSession->window_handles();
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowName()
    {
        return $this->wdSession->window_handle();
    }

    /**
     * {@inheritdoc}
     */
    public function find($xpath)
    {
        $nodes = $this->wdSession->elements('xpath', $xpath);

        $elements = array();
        foreach ($nodes as $i => $node) {
            $elements[] = new NodeElement(sprintf('(%s)[%d]', $xpath, $i+1), $this->session);
        }

        return $elements;
    }

    /**
     * {@inheritdoc}
     */
    public function getTagName($xpath)
    {
        return $this->wdSession->element('xpath', $xpath)->name();
    }

    /**
     * {@inheritdoc}
     */
    public function getText($xpath)
    {
        $node = $this->wdSession->element('xpath', $xpath);
        $text = $node->text();
        $text = (string) str_replace(array("\r", "\r\n", "\n"), ' ', $text);

        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function getHtml($xpath)
    {
        return $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.innerHTML;');
    }

    /**
     * {@inheritdoc}
     */
    public function getAttribute($xpath, $name)
    {
        $script = 'return {{ELEMENT}}.getAttribute(' . json_encode((string) $name) . ')';

        return $this->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function getValue($xpath)
    {
        $script = <<<JS
var node = {{ELEMENT}},
    tagName = node.tagName.toLowerCase(),
    value = null;

if (tagName == 'input' || tagName == 'textarea') {
    var type = node.getAttribute('type');
    if (type == 'checkbox') {
        value = node.checked;
    } else if (type == 'radio') {
        var name = node.getAttribute('name');
        if (name) {
            var fields = window.document.getElementsByName(name),
                i, l = fields.length;
            for (i = 0; i < l; i++) {
                var field = fields.item(i);
                if (field.checked) {
                    value = field.value;
                    break;
                }
            }
        }
    } else {
        value = node.value;
    }
} else if (tagName == 'select') {
    if (node.getAttribute('multiple')) {
        value = [];
        for (var i = 0; i < node.options.length; i++) {
            if (node.options[i].selected) {
                value.push(node.options[i].value);
            }
        }
    } else {
        var idx = node.selectedIndex;
        if (idx >= 0) {
            value = node.options.item(idx).value;
        } else {
            value = null;
        }
    }
} else {
    var attributeValue = node.getAttribute('value');
    if (attributeValue != null) {
        value = attributeValue;
    } else if (node.value) {
        value = node.value;
    }
}

return JSON.stringify(value);
JS;

        return json_decode($this->executeJsOnXpath($xpath, $script), true);
    }

    /**
     * {@inheritdoc}
     */
    public function setValue($xpath, $value)
    {
        $value = strval($value);

        /** @var Element $element */
        $element = $this->wdSession->element('xpath', $xpath);
        $elementName = strtolower($element->name());

        switch (true) {
            case ($elementName == 'input' && strtolower($element->attribute('type')) == 'text'):
                for ($i = 0; $i < strlen($element->attribute('value')); $i++) {
                    $value = Key::BACKSPACE . Key::DELETE . $value;
                }
                break;
            case ($elementName == 'textarea'):
            case ($elementName == 'input' && strtolower($element->attribute('type')) != 'file'):
                $element->clear();
                break;
            case ($elementName == 'select'):
                $this->selectOption($xpath, $value);

                return;
        }

        $element->value(array('value' => array($value)));
        $script = "Syn.trigger('change', {}, {{ELEMENT}})";
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function check($xpath)
    {
        if ($this->isChecked($xpath)) {
            return;
        }

        $this->click($xpath);
    }

    /**
     * {@inheritdoc}
     */
    public function uncheck($xpath)
    {
        if (!$this->isChecked($xpath)) {
            return;
        }

        $this->click($xpath);
    }

    /**
     * {@inheritdoc}
     */
    public function isChecked($xpath)
    {
        return $this->wdSession->element('xpath', $xpath)->selected();
    }

    /**
     * {@inheritdoc}
     */
    public function selectOption($xpath, $value, $multiple = false)
    {
        $valueEscaped = json_encode((string) $value);
        $multipleJS   = $multiple ? 'true' : 'false';

        $script = <<<JS
// Function to trigger an event. Cross-browser compliant. See http://stackoverflow.com/a/2490876/135494
var triggerEvent = function (element, eventName) {
    var event;
    if (document.createEvent) {
        event = document.createEvent("HTMLEvents");
        event.initEvent(eventName, true, true);
    } else {
        event = document.createEventObject();
        event.eventType = eventName;
    }

    event.eventName = eventName;

    if (document.createEvent) {
        element.dispatchEvent(event);
    } else {
        element.fireEvent("on" + event.eventType, event);
    }
};

var node = {{ELEMENT}},
    tagName = node.tagName.toLowerCase();
if (tagName == 'select') {
    var i, l = node.length;
    for (i = 0; i < l; i++) {
        if (node[i].value == $valueEscaped) {
            node[i].selected = true;
        } else if (!$multipleJS) {
            node[i].selected = false;
        }
    }
    triggerEvent(node, 'change');

} else {
    var nodes = window.document.getElementsByName(node.getAttribute('name'));
    var i, l = nodes.length;
    for (i = 0; i < l; i++) {
        if (nodes[i].getAttribute('value') == $valueEscaped) {
            node.checked = true;
        }
    }
    if (tagName == 'input') {
      var type = node.getAttribute('type');
      if (type == 'radio') {
        triggerEvent(node, 'change');
      }
    }
}
JS;


        $this->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function isSelected($xpath)
    {
        return $this->wdSession->element('xpath', $xpath)->selected();
    }

    /**
     * {@inheritdoc}
     */
    public function click($xpath)
    {
        $this->wdSession->element('xpath', $xpath)->click('');
    }

    /**
     * {@inheritdoc}
     */
    public function doubleClick($xpath)
    {
        $script = 'Syn.dblclick({{ELEMENT}})';
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function rightClick($xpath)
    {
        $script = 'Syn.rightClick({{ELEMENT}})';
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function attachFile($xpath, $path)
    {
        $this->wdSession->element('xpath', $xpath)->value(array('value'=>str_split($path)));
    }

    /**
     * {@inheritdoc}
     */
    public function isVisible($xpath)
    {
        return $this->wdSession->element('xpath', $xpath)->displayed();
    }

    /**
     * {@inheritdoc}
     */
    public function mouseOver($xpath)
    {
        $script = 'Syn.trigger("mouseover", {}, {{ELEMENT}})';
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function focus($xpath)
    {
        $script = 'Syn.trigger("focus", {}, {{ELEMENT}})';
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function blur($xpath)
    {
        $script = 'Syn.trigger("blur", {}, {{ELEMENT}})';
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function keyPress($xpath, $char, $modifier = null)
    {
        $options = self::charToOptions($char, $modifier);
        $script = "Syn.trigger('keypress', $options, {{ELEMENT}})";
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function keyDown($xpath, $char, $modifier = null)
    {
        $options = self::charToOptions($char, $modifier);
        $script = "Syn.trigger('keydown', $options, {{ELEMENT}})";
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function keyUp($xpath, $char, $modifier = null)
    {
        $options = self::charToOptions($char, $modifier);
        $script = "Syn.trigger('keyup', $options, {{ELEMENT}})";
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function dragTo($sourceXpath, $destinationXpath)
    {
        $source      = $this->wdSession->element('xpath', $sourceXpath);
        $destination = $this->wdSession->element('xpath', $destinationXpath);

        $this->wdSession->moveto(array(
            'element' => $source->getID()
        ));

        $script = <<<JS
(function (element) {
    var event = document.createEvent("HTMLEvents");

    event.initEvent("dragstart", true, true);
    event.dataTransfer = {};

    element.dispatchEvent(event);
}({{ELEMENT}}));
JS;
        $this->withSyn()->executeJsOnXpath($sourceXpath, $script);

        $this->wdSession->buttondown();
        $this->wdSession->moveto(array(
            'element' => $destination->getID()
        ));
        $this->wdSession->buttonup();

        $script = <<<JS
(function (element) {
    var event = document.createEvent("HTMLEvents");

    event.initEvent("drop", true, true);
    event.dataTransfer = {};

    element.dispatchEvent(event);
}({{ELEMENT}}));
JS;
        $this->withSyn()->executeJsOnXpath($destinationXpath, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function executeScript($script)
    {
        if (preg_match('/^function[\s\(]/', $script)) {
            $script = preg_replace('/;$/', '', $script);
            $script = '(' . $script . ')';
        }

        $this->wdSession->execute(array('script' => $script, 'args' => array()));
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateScript($script)
    {
        if (0 !== strpos(trim($script), 'return ')) {
            $script = 'return ' . $script;
        }

        return $this->wdSession->execute(array('script' => $script, 'args' => array()));
    }

    /**
     * {@inheritdoc}
     */
    public function wait($time, $condition)
    {
        $script = "return $condition;";
        $start = microtime(true);
        $end = $start + $time / 1000.0;

        do {
            $result = $this->wdSession->execute(array('script' => $script, 'args' => array()));
            usleep(100000);
        } while (microtime(true) < $end && !$result);

        return (bool) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeWindow($width, $height, $name = null)
    {
        $this->wdSession->window($name ? $name : 'current')->postSize(
            array('width' => $width, 'height' => $height)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm($xpath)
    {
        $this->wdSession->element('xpath', $xpath)->submit();
    }

    /**
     * {@inheritdoc}
     */
    public function maximizeWindow($name = null)
    {
        $this->wdSession->window($name ? $name : 'current')->maximize();
    }

    /**
     * Returns Session ID of WebDriver or `null`, when session not started yet.
     *
     * @return string|null
     */
    public function getWebDriverSessionId()
    {
        return $this->isStarted() ? basename($this->wdSession->getUrl()) : null;
    }
}