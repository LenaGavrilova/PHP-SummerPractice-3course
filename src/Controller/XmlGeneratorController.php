<?php


namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XmlGeneratorController extends AbstractController
{
    public const XSD_DIR = "/static/xsd/";

    #[Route('/upload-xsd/{path}', name: 'upload_xsd', requirements: ["path" => ".*"])]
    public function uploadXsd(Request $request, string $path): Response
    {
        try {
            $filePath = $this->getParameter('kernel.project_dir') . self::XSD_DIR . "/" . $path;
            $xsdContent = file_get_contents($filePath);
            $fields = $this->parseXsd($xsdContent);
            return $this->render('form.html.twig', ['fields' => $fields]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка при обработке XSD файла.');
        }

        // Если файл не загружен или произошла ошибка
        return $this->render('upload.html.twig');
    }

    #[Route('/use-default-schema', name: 'use_default_schema')]
    public function useDefaultSchema(): Response
    {
        $defaultXsdContent = '
        <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
        <xs:element name="root">
            <xs:complexType>
                <xs:sequence>
                    <xs:element name="exampleField1" type="xs:string"/>
                    <xs:element name="exampleField2" type="xs:date"/>
                </xs:sequence>
            </xs:complexType>
        </xs:element>
    </xs:schema>';
        $fields = $this->parseXsd($defaultXsdContent);

        return $this->render('form.html.twig', ['fields' => $fields]);
    }

    #[Route('/generate-xml', name: 'generate_xml', methods: ['POST'])]
    public function generateXml(Request $request): Response
    {
        $fields = $request->request->all('fields');
        $errors = $this->validateFields($fields);

        if (!empty($errors)) {
            // Если есть ошибки, отобразим форму снова с сообщениями об ошибках
            $this->addFlash('error', implode(' ', $errors));
            return $this->redirectToRoute('upload_xsd');
        }

        $xmlContent = $this->generateXmlContent($fields);

        $response = new Response($xmlContent);
        $response->headers->set('Content-Type', 'application/xml');
        $response->headers->set('Content-Disposition', 'attachment; filename="data.xml"');

        return $response;
    }

    private function validateFields(array $fields): array
    {
        $errors = [];

        foreach ($fields as $complexType => $complexTypeFields) {
            foreach ($complexTypeFields as $fieldName => $field) {
                $value = $field['value'] ?? '';
                $type = $field['type'] ?? '';

                if ($this->isTypeStr($type)) {
                    $expectedLength = $this->getMaxLengthFromXsd($type);
                    if (!ctype_digit($value) && strlen($value) > $expectedLength) {
                        $errors[] = "Поле '$fieldName' не должно превышать $expectedLength символа.";
                    }
                }

                if ($this->isTypeDigits($type)) {
                    $expectedLength = $this->getDigitsLength($type);
                    if (!ctype_digit($value) || strlen($value) !== $expectedLength) {
                        $errors[] = "Поле '$fieldName' должно быть числом и содержать ровно $expectedLength цифр.";
                    }
                }
            }
        }

        return $errors;
    }


    private function getMaxLengthFromXsd(string $type)
    {
        preg_match('/string-(\d+)/', $type, $matches);
        return intval($matches[1] ?? 0);
    }
    private function isTypeDigits(string $type): bool
    {
        return (strpos($type, 'digits-') !== false or strpos($type, 'int-') !== false or
            strpos($type, 'integer-') !== false);
    }

    private function isTypeStr(string $type): bool
    {
        return strpos($type, 'string-') !== false;
    }

    private function getDigitsLength(string $type): int
    {
        preg_match('/digits-(\d+)/', $type, $matches);
        return intval($matches[1] ?? 0);
    }

    private function generateXmlContent(array $fields): string
    {
        $xml = new \SimpleXMLElement('<data/>');

        foreach ($fields as $parent => $children) {

            if (empty($children)) {
                continue;
            }


            $parentElement = $xml->addChild($parent);

            foreach ($children as $child) {
                $name = $child['name'];
                $value = $child['value'] ?? '';
                $description = $child['description'] ?? '';


                if ($value !== '' || $description !== '') {
                    $childElement = $parentElement->addChild($name, htmlspecialchars($value));


                    if (!empty($description)) {
                        $childElement->addAttribute('description', htmlspecialchars($description));
                    }
                }
            }
        }

        return $xml->asXML();
    }

    /**
     * @throws \Exception
     **/
    private function parseXsd(string $xsdContent): array
    {
        $fields = [];
        $processedElements = [];
        $xml = new \SimpleXMLElement($xsdContent);
        $xml->registerXPathNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

        // Process complexType definitions
        foreach ($xml->xpath('//xs:complexType') as $complexType) {
            $typeName = (string) $complexType['name'];
            $this->processComplexType($complexType, $typeName, $fields, $processedElements);
        }

        // Process elements and their types
        foreach ($xml->xpath('//xs:element') as $element) {
            $typeName = (string) $element['name'];
            if (!in_array($typeName, $processedElements)) {
                $field = $this->parseElement($element);
                $fields[$typeName][] = $field;
                $processedElements[] = $typeName;
            }

            // Handle complex types defined within elements
            if (isset($element->complexType)) {
                $this->processComplexType($element->complexType, $typeName, $fields, $processedElements);
            }
        }

        // Process simpleType definitions with enumerations
        foreach ($xml->xpath('//xs:simpleType[xs:restriction/xs:enumeration]') as $simpleType) {
            $typeName = (string) $simpleType['name'];

            $field = [
                'name' => $typeName,
                'type' => 'enum',
                'description' => '',
                'htmlType' => 'select',
                'options' => [],
            ];

            foreach ($simpleType->restriction->enumeration as $enumeration) {
                $value = (string) $enumeration['value'];
                $field['options'][$value] = (string) $enumeration->annotation->documentation;
            }

            $fields[$typeName] = [$field];
        }

        return $fields;
    }

    private function processComplexType(\SimpleXMLElement $complexType, string $typeName, array &$fields, array &$processedElements)
    {
        if (isset($complexType->sequence)) {
            foreach ($complexType->sequence->element as $element) {
                $field = $this->parseElement($element);
                $fields[$typeName][] = $field;
                $processedElements[] = (string) $element['name'];
            }
        }
    }

    private function parseElement(\SimpleXMLElement $element): array
    {
        $field = [
            'name' => (string) $element['name'],
            'type' => (string) $element['type'],
            'description' => isset($element->annotation->documentation) ? (string) $element->annotation->documentation : '',
            'minLength' => null,
            'maxLength' => null,
            'pattern' => null,
            'htmlType' => 'text',
            'options' => [],
        ];

        switch ($field['type']) {
            case 'xs:date':
            case 'date':
                $field['htmlType'] = 'date';
                break;
            case 'xs:dateTime':
                $field['htmlType'] = 'datetime-local';
                break;
            case 'xs:time':
                $field['htmlType'] = 'time';
                break;
            case 'xs:boolean':
                $field['htmlType'] = 'checkbox';
                break;
        }

        if (
            str_contains($field['type'], 'string') or str_contains($field['type'], 'normalizedString') or
            str_contains($field['type'], 'token')
        ) {
            $field['htmlType'] = 'string';
            preg_match('/string-(\d+)/', $field['type'], $matches);
            if (!empty($matches)) {
                $field['maxLength'] = (int) $matches[1];
            }
        }

        if (
            str_contains($field['type'], 'digits') or str_contains($field['type'], 'decimal') or
            str_contains($field['type'], 'float') or str_contains($field['type'], 'double') or
            str_contains($field['type'], 'integer') or str_contains($field['type'], 'long') or
            str_contains($field['type'], 'int') or str_contains($field['type'], 'short') or
            str_contains($field['type'], 'byte') or str_contains($field['type'], 'nonPositiveInteger') or
            str_contains($field['type'], 'negativeInteger') or str_contains($field['type'], 'nonNegativeInteger') or
            str_contains($field['type'], 'unsignedLong') or str_contains($field['type'], 'unsignedInt') or
            str_contains($field['type'], 'unsignedShort') or str_contains($field['type'], 'unsignedByte') or
            str_contains($field['type'], 'positiveInteger')
        ) {
            $field['htmlType'] = 'digits';
            preg_match('/digits-(\d+)/', $field['type'], $matches);
            if (!empty($matches)) {
                $field['maxLength'] = (int) $matches[1];
                $field['minLength'] = (int) $matches[1];
                $field['pattern'] = '\d{' . (int) $matches[1] . '}';
            }
        }

        if (isset($element->simpleType)) {
            $field['type'] = 'enum';
            foreach ($element->simpleType->restriction->enumeration as $enumeration) {
                $value = (string) $enumeration['value'];
                $field['options'][$value] = (string) $enumeration->annotation->documentation;
            }
            $field['htmlType'] = 'select';
        }

        return $field;
    }

}
