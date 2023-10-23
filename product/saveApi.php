<?php

require_once '../autoload/Autoloader.php';

use App\Database;
use App\Dvd;
use App\Book;
use App\Furniture;

class SaveApi
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function handleRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $jsonInput = file_get_contents('php://input');
            $productData = json_decode($jsonInput, true);
    
            $action = isset($productData['action']) ? $productData['action'] : '';
    
            if (isset($productData['sku'])) {
                $productType = $productData['productType'];
    
                $commonErrors = $this->validateCommonFields($productData);
                $productTypeErrors = $this->validateProductTypeFields($productType, $productData);
                $existingSkuError = $this->validateExistingSku($productData['sku']);
                
                // Check for validation errors
                $errors = array_merge($commonErrors, $productTypeErrors);
    
                if ($existingSkuError) {
                    $errors['sku'] = $existingSkuError;
                }
    
                if (empty($errors)) {
                    $product = $this->createProduct($productType, $productData);
    
                    if ($product) {
                        try {
                            $product->save();
                            session_start();
                            $_SESSION['last_added_sku'] = $productData['sku'];
    
                            return ['message' => 'Product saved successfully', 'redirect' => 'index.php'];
                        } catch (\Exception $e) {
                            return ['error' => 'Failed to save the product'];
                        }
                    } else {
                        return ['error' => 'Invalid product type'];
                    }
                } else {
                    return ['errors' => $errors];
                }
            } elseif ($action === 'cancel') {
                return $this->cancelProduct($action);
            } elseif (isset($productData['productIds'])) {
                return $this->deleteProducts($productData);
            }
        }
    
        return ['error' => 'Invalid request'];
    }
    
    private function createProduct($productType, $productData)
    {
        $this->validateProductData($productData);

        switch ($productType) {
            case 'book':
                return $this->createBook($productData);
            case 'dvd':
                return $this->createDvd($productData);
            case 'furniture':
                return $this->createFurniture($productData);
            default:
                throw new \Exception('Invalid product type');
        }
    }

    private function createBook($productData)
    {
        $this->validateNumericFields(['weight'], $productData);
        return new Book(
            $productData['sku'],
            $productData['name'],
            $productData['price'],
            $productData['weight'],
            $this->db
        );
    }

    private function createDvd($productData)
    {
        $this->validateNumericFields(['size'], $productData);
        return new Dvd(
            $productData['sku'],
            $productData['name'],
            $productData['price'],
            $productData['size'],
            $this->db
        );
    }

    private function createFurniture($productData)
    {
        $this->validateNumericFields(['height', 'width', 'length'], $productData);
        return new Furniture(
            $productData['sku'],
            $productData['name'],
            $productData['price'],
            $productData['height'],
            $productData['width'],
            $productData['length'],
            $this->db
        );
    }

    private function validateProductData($productData)
    {
        $requiredFields = ['sku', 'name', 'price', 'productType'];
        $errors = [];

        foreach ($requiredFields as $field) {
            if (!isset($productData[$field]) || empty($productData[$field])) {
                $errors[$field] = ucfirst($field) . ' field is required.';
            }
        }

        if (!empty($errors)) {
            throw new \Exception('Invalid product data: ' . implode(', ', $errors));
        }
    }

    private function validateNumericFields($fields, $productData)
    {
        $errors = [];

        foreach ($fields as $field) {
            if (!is_numeric($productData[$field])) {
                $errors[$field] = ucfirst($field) . ' must be a valid number.';
            }
        }

        if (!empty($errors)) {
            throw new \Exception('Invalid product data: ' . implode(', ', $errors));
        }
    }

    private function validateCommonFields($productData)
    {
        $requiredFields = ['sku', 'name', 'price', 'productType'];
        $errors = [];

        foreach ($requiredFields as $field) {
            if (!isset($productData[$field]) || empty($productData[$field])) {
                $errors[$field] = ucfirst($field) . ' field is required.';
            }
        }

        return $errors;
    }

    private function validateExistingSku($sku)
    {
        $skuCheckQuery = "SELECT COUNT(*) FROM products WHERE sku = :sku";
        $skuCheckStatement = $this->db->getConnection()->prepare($skuCheckQuery);
        $skuCheckStatement->execute(['sku' => $sku]);
        $skuCount = $skuCheckStatement->fetchColumn();

        if ($skuCount > 0) {
            return 'This SKU has already been used';
        }

        return null; // No error
    }

    private function validateProductTypeFields($productType, $productData)
    {
        $productTypeErrors = [];

        if ($productType === 'dvd' && !is_numeric($productData['size'])) {
            $productTypeErrors['size'] = 'Size must be a valid number.';
        }

        if ($productType === 'book' && !is_numeric($productData['weight'])) {
            $productTypeErrors['weight'] = 'Weight must be a valid number.';
        }

        if ($productType === 'furniture') {
            if (!is_numeric($productData['height'])) {
                $productTypeErrors['height'] = 'Height must be a valid number.';
            }

            if (!is_numeric($productData['width'])) {
                $productTypeErrors['width'] = 'Width must be a valid number.';
            }

            if (!is_numeric($productData['length'])) {
                $productTypeErrors['length'] = 'Length must be a valid number.';
            }
        }

        return $productTypeErrors;
    }

    private function cancelProduct($action)
    {
        if ($action) {
            try {
                session_start();
                if (isset($_SESSION['last_added_sku'])) {
                    $lastAddedSku = $_SESSION['last_added_sku'];
                    $stmt = $this->db->getConnection()->prepare("DELETE FROM products WHERE sku = :sku");
                    $stmt->bindParam(':sku', $lastAddedSku, PDO::PARAM_STR);
                    $stmt->execute();
                    $stmt->closeCursor();
                }

                return ['message' => 'Product canceled successfully', 'redirect' => 'index.php'];
            } catch (\Exception $e) {
                return ['error' => 'Failed to cancel the product'];
            }
        }

        return ['error' => 'Invalid product type'];
    }

    private function deleteProducts($productData)
    {
        $productIds = $productData['productIds'];
        // Loop through the selected product IDs and delete them from the database
        $deleteStatement = $this->db->getConnection()->prepare("DELETE FROM products WHERE id = :productId");

        // Loop through the selected product IDs and execute the delete statement
        foreach ($productIds as $productId) {
            $deleteStatement->bindParam(':productId', $productId, PDO::PARAM_INT);
            $deleteStatement->execute();
        }

        return ['message' => 'Products deleted successfully'];
    }
}

$config = require '../config.php';
$db = new Database($config);
$api = new SaveApi($db);
$response = $api->handleRequest();

header('Content-Type: application/json');
echo json_encode($response);