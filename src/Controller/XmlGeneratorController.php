<?php


namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XmlGeneratorController extends AbstractController
{
    #[Route('/upload-xsd', name: 'upload_xsd')]
    public function uploadXsd(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            /** @var UploadedFile $xsdFile */
            $xsdFile = $request->files->get('xsd');
            if ($xsdFile) {
                try {
                    $xsdContent = file_get_contents($xsdFile->getPathname());
                    $fields = $this->parseXsd($xsdContent);
                    return $this->render('form.html.twig', ['fields' => $fields]);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Ошибка при обработке XSD файла.');
                }
            } else {
                $this->addFlash('error', 'Файл не загружен.');
            }
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
            $this->addFlash('error', implode('<br>', $errors));
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

        foreach ($fields as $field) {
            $value = $field['value'] ?? '';
            $name = $field['name'] ?? '';
            $type = $field['type'] ?? '';
            $minLength = $field['minLength'] ?? null;
            $maxLength = $field['maxLength'] ?? null;

            if ($type == 'xs:string') {
                if ($minLength !== null && strlen($value) < $minLength) {
                    $errors[] = "Поле '$name' должно быть не короче $minLength символов.";
                }
                if ($maxLength !== null && strlen($value) > $maxLength) {
                    $errors[] = "Поле '$name' должно быть не длиннее $maxLength символов.";
                }
            }

        }

        return $errors;
    }
    private function generateXmlContent(array $fields): string
    {
        $xml = new \SimpleXMLElement('<data/>');

        foreach ($fields as $fieldName => $fieldData) {
            $value = $fieldData['value'] ?? '';
            $description = $fieldData['description'] ?? '';

            // Create XML element for the field
            $child = $xml->addChild($fieldName, htmlspecialchars($value));

            // Add description attribute if available
            if (!empty($description)) {
                $child->addAttribute('description', htmlspecialchars($description));
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
            $typeName = (string)$complexType['name'];
            $this->processComplexType($complexType, $typeName, $fields, $processedElements);
        }

        // Process elements and their types
        foreach ($xml->xpath('//xs:element') as $element) {
            $typeName = (string)$element['name'];
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
            $typeName = (string)$simpleType['name'];

            $field = [
                'name' => $typeName,
                'type' => 'enum',
                'description' => '',
                'htmlType' => 'select',
                'options' => [],
            ];

            foreach ($simpleType->restriction->enumeration as $enumeration) {
                $value = (string)$enumeration['value'];
                $field['options'][$value] = (string)$enumeration->annotation->documentation;
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
                $processedElements[] = (string)$element['name'];
            }
        }
    }

    private function parseElement(\SimpleXMLElement $element): array
    {
        $field = [
            'name' => (string)$element['name'],
            'type' => (string)$element['type'],
            'description' => isset($element->annotation->documentation) ? (string)$element->annotation->documentation : '',
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

        if (str_contains($field['type'], 'string')) {
            $field['htmlType'] = 'string';
        }
        if (str_contains($field['type'],'digits')){
            $field['htmlType'] = 'digits';
        }

        if (isset($element->simpleType)) {
            $field['type'] = 'enum';
            foreach ($element->simpleType->restriction->enumeration as $enumeration) {
                $value = (string)$enumeration['value'];
                $field['options'][$value] = (string)$enumeration->annotation->documentation;
            }
            $field['htmlType'] = 'select';
        }

        return $field;
    }


}