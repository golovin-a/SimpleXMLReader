<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

declare (strict_types=1);

namespace SimpleXMLReader;

use DOMCharacterData;
use DomDocument;
use DOMException;
use Exception;
use SimpleXMLElement;
use XMLReader;

use function array_slice;
use function array_splice;
use function call_user_func;
use function count;
use function is_callable;
use function simplexml_import_dom;

class SimpleXMLReader extends XMLReader
{
    /**
     * Callbacks
     *
     * @var callable[]
     */
    protected array $callback = [];

    /**
     * Depth
     */
    protected int $currentDepth = 0;

    /**
     * Previous depth
     */
    protected int $prevDepth = 0;

    /**
     * Stack of the parsed nodes
     */
    protected array $nodesParsed = [];

    /**
     * Stack of the node types
     */
    protected array $nodesType = [];

    /**
     * Stack of node position
     */
    protected array $nodesCounter = [];

    /**
     * Do not remove redundant white space.
     */
    public bool $preserveWhiteSpace = true;

    /**
     * Add node callback
     * @throws Exception
     */
    public function registerCallback(string $xpath, callable $callback, int $nodeType = XMLReader::ELEMENT): static
    {
        if (isset($this->callback[$nodeType][$xpath])) {
            throw new Exception("Already exists callback '$xpath':$nodeType.");
        }
        if (!is_callable($callback)) {
            throw new Exception("Not callable callback '$xpath':$nodeType.");
        }
        $this->callback[$nodeType][$xpath] = $callback;
        return $this;
    }

    /**
     * Remove node callback
     * @throws Exception
     */
    public function unRegisterCallback(string $xpath, int $nodeType = XMLReader::ELEMENT): static
    {
        if (!isset($this->callback[$nodeType][$xpath])) {
            throw new Exception("Unknown parser callback '$xpath':$nodeType.");
        }
        unset($this->callback[$nodeType][$xpath]);
        return $this;
    }

    /**
     * Moves cursor to the next node in the document.
     *
     * @link http://php.net/manual/en/xmlreader.read.php
     * @return bool
     *   Returns TRUE on success or FALSE on failure.
     * @throws Exception
     */
    public function read(): bool
    {
        $read = parent::read();
        if ($this->depth < $this->prevDepth) {
            if (!isset($this->nodesParsed[$this->depth])) {
                throw new Exception("Invalid xml: missing items in SimpleXMLReader::\$nodesParsed");
            }
            if (!isset($this->nodesCounter[$this->depth])) {
                throw new Exception("Invalid xml: missing items in SimpleXMLReader::\$nodesCounter");
            }
            if (!isset($this->nodesType[$this->depth])) {
                throw new Exception("Invalid xml: missing items in SimpleXMLReader::\$nodesType");
            }
            $this->nodesParsed = array_slice($this->nodesParsed, 0, $this->depth + 1, true);
            $this->nodesCounter = array_slice($this->nodesCounter, 0, $this->depth + 1, true);
            $this->nodesType = array_slice($this->nodesType, 0, $this->depth + 1, true);
        }
        if (isset($this->nodesParsed[$this->depth]) && $this->localName == $this->nodesParsed[$this->depth] && $this->nodeType == $this->nodesType[$this->depth]) {
            $this->nodesCounter[$this->depth] = $this->nodesCounter[$this->depth] + 1;
        } else {
            $this->nodesParsed[$this->depth] = $this->localName;
            $this->nodesType[$this->depth] = $this->nodeType;
            $this->nodesCounter[$this->depth] = 1;
        }
        $this->prevDepth = $this->depth;
        return $read;
    }

    /**
     * Return current xpath node
     * @throws Exception
     */
    public function currentXpath(bool $nodesCounter = false): string
    {
        if (count($this->nodesCounter) != count($this->nodesParsed) && count($this->nodesCounter) != count(
                $this->nodesType
            )) {
            throw new Exception("Empty reader");
        }
        $result = "";
        foreach ($this->nodesParsed as $depth => $name) {
            switch ($this->nodesType[$depth]) {
                case self::ELEMENT:
                    $result .= "/" . $name;
                    if ($nodesCounter) {
                        $result .= "[" . $this->nodesCounter[$depth] . "]";
                    }
                    break;

                case self::TEXT:
                case self::CDATA:
                    $result .= "/text()";
                    break;

                case self::COMMENT:
                    $result .= "/comment()";
                    break;

                case self::ATTRIBUTE:
                    $result .= "[@$name]";
                    break;
            }
        }
        return $result;
    }

    /**
     * Run parser
     * @throws Exception
     */
    public function parse(): void
    {
        if (empty($this->callback)) {
            throw new Exception("Empty parser callback.");
        }
        $continue = true;
        while ($continue && $this->read()) {
            if (!isset($this->callback[$this->nodeType])) {
                continue;
            }
            if (isset($this->callback[$this->nodeType][$this->name])) {
                $continue = call_user_func($this->callback[$this->nodeType][$this->name], $this);
            } else {
                $xpath = $this->currentXpath(); // without node counter
                if (isset($this->callback[$this->nodeType][$xpath])) {
                    $continue = call_user_func($this->callback[$this->nodeType][$xpath], $this);
                } else {
                    $xpath = $this->currentXpath(true); // with node counter
                    if (isset($this->callback[$this->nodeType][$xpath])) {
                        $continue = call_user_func($this->callback[$this->nodeType][$xpath], $this);
                    }
                }
            }
        }
    }

    /**
     * Run XPath query on current node
     *
     * @param string $path
     * @param string $version
     * @param string $encoding
     * @param string|null $className
     * @return array|false|null array<SimpleXMLElement>|false|null
     *   array<SimpleXMLElement>|false|null
     * @throws DOMException
     */
    public function expandXpath(
        string $path,
        string $version = "1.0",
        string $encoding = "UTF-8",
        ?string $className = null
    ): array|false|null {
        return $this->expandSimpleXml($version, $encoding, $className)->xpath($path);
    }

    /**
     * Expand current node to string
     * @throws DOMException
     */
    public function expandString(
        string $version = "1.0",
        string $encoding = "UTF-8",
        ?string $className = null
    ): string|false {
        return $this->expandSimpleXml($version, $encoding, $className)->asXML();
    }

    /**
     * Expand current node to SimpleXMLElement
     * @throws DOMException
     */
    public function expandSimpleXml(
        string $version = "1.0",
        string $encoding = "UTF-8",
        ?string $className = null
    ): SimpleXMLElement {
        $element = $this->expand();
        $document = new DomDocument($version, $encoding);
        $document->preserveWhiteSpace = $this->preserveWhiteSpace;
        if ($element instanceof DOMCharacterData) {
            $nodeName = array_splice($this->nodesParsed, -2, 1);
            $nodeName = (isset($nodeName[0]) && $nodeName[0] ? $nodeName[0] : "root");
            $node = $document->createElement($nodeName);
            $node->appendChild($element);
            $element = $node;
        }
        $node = $document->importNode($element, true);
        $document->appendChild($node);
        return simplexml_import_dom($node, $className);
    }

    /**
     * Expand current node to DomDocument
     * @throws DOMException
     */
    public function expandDomDocument(string $version = "1.0", string $encoding = "UTF-8"): DOMDocument
    {
        $element = $this->expand();
        $document = new DomDocument($version, $encoding);
        $document->preserveWhiteSpace = $this->preserveWhiteSpace;
        if ($element instanceof DOMCharacterData) {
            $nodeName = array_splice($this->nodesParsed, -2, 1);
            $nodeName = (isset($nodeName[0]) && $nodeName[0] ? $nodeName[0] : "root");
            $node = $document->createElement($nodeName);
            $node->appendChild($element);
            $element = $node;
        }
        $node = $document->importNode($element, true);
        $document->appendChild($node);
        return $document;
    }
}