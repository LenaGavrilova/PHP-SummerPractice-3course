<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
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
        $defaultXsdContent = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
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

        foreach ($fields as $field) {
            $child = $xml->addChild($field['name'], htmlspecialchars($field['value']));
            if (!empty($field['description'])) {
                $child->addAttribute('description', htmlspecialchars($field['description']));
            }
        }

        return $xml->asXML();
    }

    /**
     * @throws \Exception
     */
    private function parseXsd(string $xsdContent): array
    {
        $fields = [];
        $xml = new \SimpleXMLElement($xsdContent);
        $xml->registerXPathNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

        foreach ($xml->xpath('//xs:element') as $element) {
            $field = [
                'name' => (string) $element['name'],
                'type' => (string) $element['type'],
                'description' => (string) $element['description'] ?? '',
                'minLength' => null,
                'maxLength' => null,
                'pattern' => null,
                'htmlType' => 'text',
            ];
            if (strpos($field['type'], 'tns:') === 0) {
                $typeName = substr($field['type'], 4); // Remove 'tns:'
                $typeDefinition = $xml->xpath("//xs:simpleType[@name='{$typeName}']");

                if ($typeDefinition) {
                    foreach ($typeDefinition[0]->restriction->children('xs', true) as $constraint) {
                        switch ($constraint->getName()) {
                            case 'minLength':
                                $field['minLength'] = (int) $constraint['value'];
                                break;
                            case 'maxLength':
                                $field['maxLength'] = (int) $constraint['value'];
                                break;
                            case 'pattern':
                                $field['pattern'] = (string) $constraint['value'];
                                break;
                        }
                    }
                }
            }

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


            $fields[] = $field;
        }

        return $fields;
    }
}

