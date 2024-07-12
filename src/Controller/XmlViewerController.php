<?php

namespace App\Controller;

use App\Service\XsdService;
use GoetasWebservices\XML\XSDReader\Schema\Element\ElementDef;
use GoetasWebservices\XML\XSDReader\Schema\Element\ElementRef;
use GoetasWebservices\XML\XSDReader\Schema\Schema;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use GoetasWebservices\XML\XSDReader\SchemaReader;
use GoetasWebservices\XML\XSDReader\Documentation\StandardDocumentationReader;

use DOMDocument;

class XmlViewerController extends AbstractController
{
    private const DEFAULT_NAMESPACE_URI = "http://www.w3.org/2001/XMLSchema";
    private XsdService $xsdService;
    private SchemaReader $schemaReader;
    private StandardDocumentationReader $docReader;
    public function __construct(XsdService $xsdService)
    {
        $this->xsdService = $xsdService;
        $this->schemaReader = new SchemaReader();
        $this->docReader = new StandardDocumentationReader();
    }

    #[Route('/view-xml-upload', name: 'view_xml_upload', methods: ['GET'])]
    public function viewXmlUploadForm(Request $request): Response
    {
        // Render form for xml upload
        return $this->render('xml_viewer/upload_xml.html.twig');
    }

    #[Route('/view-xml', name: 'view_xml', methods: ['POST'])]
    public function viewXmlUploadFormPost(Request $request): Response
    {
        // Get XML and XSD files, show XML file

        /** @var UploadedFile $xmlFile */
        $xmlFile = $request->files->get('xml_file');
        $xsdPath = $request->request->get("xsd_path");

        $xsdPathFileSystem = $this->xsdService->getXsdFileSystemPath($xsdPath);

        // Load XML file
        $xmlDom = new DOMDocument();
        $xmlDom->load($xmlFile);

        // Validate XML according to XSD
        libxml_use_internal_errors(true);
        $xmlDom->schemaValidate($xsdPathFileSystem);
        if (libxml_get_errors()) {
            $errorMessages = [];
            foreach (libxml_get_errors() as $error) {
                $errorMessages[] = $error->message;
            }
            libxml_clear_errors();
            return $this->render('xml_viewer/view_xml.html.twig', [
                'errors' => $errorMessages
            ]);
        }

        // Load XSD as regular XML file using DOMDocument
        $xsdDom = new DOMDocument();
        $xsdDom->load($xsdPathFileSystem);

        // Get XML base element name and namespace
        $xmlLocalName = $xmlDom->documentElement->localName;
        $xmlNamespace = $xmlDom->documentElement->namespaceURI;

        // Find this element declaration in XSD
        /**
         * @var \DOMElement
         */
        $baseElementInXsd = null;
        foreach ($xsdDom->getElementsByTagNameNS(self::DEFAULT_NAMESPACE_URI, "element") as $element) {
            if ($element->attributes->getNamedItem('name')->nodeValue == $xmlLocalName) {
                $baseElementInXsd = $element;
                break;
            }
        }
        // Now find complexType for baseElementInXsd. It is either inside this node (without name) 
        // or somewhere else with name <target_namespace>:<local_name>
        $complexType = $this->findComplexTypeElement($baseElementInXsd, $xsdDom);

        // Get schema using schemaReader
        $schema = $this->schemaReader->readFile($xsdPathFileSystem);

        // Find element of this XML in the XSD
        $tagNameExplode = explode(":", $xmlDom->documentElement->tagName);
        $rootElementTagNameNoNamespace = array_pop($tagNameExplode);
        $schemaElement = null;
        foreach ($schema->getElements() as $element) {
            // if tag name AND namespace URI are the same
            if ($element->getName() === $rootElementTagNameNoNamespace && $xmlDom->documentElement->namespaceURI === $element->getSchema()->getTargetNamespace()) {
                $schemaElement = $element;
                break;
            }
        }
        if ($schemaElement === null) {
            return $this->render('xml_viewer/view_xml.html.twig', [
                'errors' => ["Не удалось найти корневой элемент XML в схеме XSD"]
            ]);
        }

        $html = $this->parseXml($xmlDom, $complexType, $baseElementInXsd);

        return $this->render('xml_viewer/view_xml.html.twig', [
            'xml' => $html
        ]);
    }

    private function parseXml(DOMDocument $xmlDom, \DOMNode $type, \DOMNode $baseElementInXsd)
    {
        $html = '<pre>';

        $html .= '<h2>' . $this->docReader->get($baseElementInXsd) . '</h2>';
        $html .= "\n";
        $this->processNode($xmlDom->documentElement, $type, $html, 0);

        $html .= '</pre>';

        return $html;
    }

    private function processNode(\DOMElement $currentElement, \DOMNode $type, string &$html, int $depth)
    {
        $doc = $this->docReader->get($type);
        if ($doc) {
            $doc = "(" . $doc . ")";
        }
        $tab = str_repeat(" ", 2 * $depth);
        $html .= $tab . "<b>" . $currentElement->localName . $doc . "</b>";

        $text = $this->getElementText($currentElement);
        if ($text !== null) {
            $html .= ": " . $text;
        }
        if ($currentElement->attributes !== null) {
            foreach ($currentElement->attributes as $attr) {
                $html .= " " . $attr->nodeName . ": " . $attr->nodeValue . "; ";
            }
        }
        $html .= "\n";

        // Find sequence in $type
        foreach ($type->childNodes as $childNode) {
            if ($childNode->localName === "sequence" && $childNode->namespaceURI === self::DEFAULT_NAMESPACE_URI) {
                for ($i = 0; $i < count($childNode->childNodes); $i++) {
                    if (get_class($childNode->childNodes->item($i)) === \DOMElement::class && $currentElement->childNodes->item($i) !== null) {
                        $this->processNode($currentElement->childNodes->item($i), $childNode->childNodes->item($i), $html, $depth + 1);
                    }
                }
                return;
            }
        }


    }

    private function getNamespaces(DOMDocument $xsdDom)
    {
        $schemaElement = $xsdDom->getElementsByTagName("schema")->item(0);

        $namespaces = [];
        $targetNamespaceUri = "";

        foreach ($schemaElement->attributes as $attribute) {
            $attributeNameExplode = explode(":", $attribute->nodeName);
            if ($attributeNameExplode[0] === "xmlns") {
                $namespaceURI = $attribute->nodeValue;
                $namespaceShorcut = count($attributeNameExplode) > 1 ? $attributeNameExplode[1] : "";

                $namespaces[$namespaceURI] = $namespaceShorcut;

            } elseif ($attribute->nodeName === "targetNamespace") {
                $targetNamespaceUri = $attribute->nodeValue;
            }
        }

        return [$namespaces, $targetNamespaceUri];
    }

    private function findComplexTypeElement(\DOMNode $node, DOMDocument $xsdDom): \DOMNode
    {
        // Try to find inside node
        foreach ($node->childNodes as $childNode) {
            if ($childNode->localName === "complexType" && $childNode->namespaceURI === self::DEFAULT_NAMESPACE_URI) {
                return $childNode;
            }
        }

        $elementType = $node->attributes->getNamedItem("type")->nodeValue;
        $elementTypeExplode = explode(":", $elementType);
        $namespaceShortcut = $elementTypeExplode[0];
        $localName = $elementTypeExplode[1];



        // Try to find in this XSD
        foreach ($xsdDom->getElementsByTagNameNS(self::DEFAULT_NAMESPACE_URI, "complexType") as $elem) {
            if ($elem->attributes->getNamedItem("name")->nodeValue === $localName) {
                return $elem;
            }
        }

        throw new \RuntimeException("Could not find this type");
    }

    private function getElementText(\DOMElement $element): string|null
    {
        foreach ($element->childNodes as $node) {

            if ($node->nodeType === XML_TEXT_NODE) {
                return trim($node->nodeValue);
            }
        }
        return null;
    }
}
