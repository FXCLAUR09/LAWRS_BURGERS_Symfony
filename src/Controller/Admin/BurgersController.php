<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BurgersController extends AbstractController
{
    /*
    |--------------------------------------------------------------------------
    | BURGERS LIST
    |--------------------------------------------------------------------------
    */

    #[Route('/admin/burgers', name: 'admin_burgers', methods: ['GET'])]
    public function index(Connection $connection): Response
    {
        $burgers = $connection->fetchAllAssociative(
            'SELECT id, name, description, price, image, category,
                    available, stock_quantity, created_at
             FROM burgers
             ORDER BY id DESC'
        );

        return $this->render('Admin/burgers.html.twig', [
            'burgers' => $burgers,
            'admin_username' => 'Admin',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ADD BURGER
    |--------------------------------------------------------------------------
    */

    #[Route('/admin/burgers/add', name: 'admin_burger_add', methods: ['GET', 'POST'])]
    public function add(Request $request, Connection $connection): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $error = '';

        $form = [
            'name' => '',
            'description' => '',
            'price' => '',
            'category' => '',
            'stock_quantity' => '',
            'available' => '1',
        ];

        if ($request->isMethod('POST')) {
            $form['name'] = trim((string) $request->request->get('name', ''));
            $form['description'] = trim((string) $request->request->get('description', ''));
            $form['price'] = trim((string) $request->request->get('price', ''));
            $form['category'] = trim((string) $request->request->get('category', ''));
            $form['stock_quantity'] = trim((string) $request->request->get('stock_quantity', ''));
            $form['available'] = $request->request->has('available') ? '1' : '0';

            $image = $request->files->get('image');

            $categories = [
                'Signature Burgers',
                'Classic Burgers',
                'Premium Burgers',
                'Special Burgers',
            ];

            if ($form['name'] === '') {
                $error = 'Please enter the burger name.';
            } elseif ($form['description'] === '') {
                $error = 'Please enter a burger description.';
            } elseif (
                $form['price'] === '' ||
                !is_numeric($form['price']) ||
                !is_finite((float) $form['price']) ||
                (float) $form['price'] < 0
            ) {
                $error = 'Please enter a valid price.';
            } elseif (!in_array($form['category'], $categories, true)) {
                $error = 'Please select a valid category.';
            } elseif (
                $form['stock_quantity'] === '' ||
                !ctype_digit($form['stock_quantity'])
            ) {
                $error = 'Please enter a valid stock quantity.';
            } elseif (!$image instanceof UploadedFile || !$image->isValid()) {
                $error = 'Please select a valid burger image.';
            } elseif (!in_array($image->getMimeType(), [
                'image/jpeg',
                'image/png',
                'image/webp',
            ], true)) {
                $error = 'Invalid image type. Please use JPG, PNG, or WEBP.';
            } elseif ($image->getSize() > 5 * 1024 * 1024) {
                $error = 'Image is too large. Maximum file size is 5 MB.';
            } else {
                $extensionMap = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                $extension = $extensionMap[$image->getMimeType()];

                $safeName = preg_replace(
                    '/[^a-z0-9-]/',
                    '-',
                    strtolower($form['name'])
                );

                $safeName = trim($safeName, '-');

                if ($safeName === '') {
                    $safeName = 'burger';
                }

                $fileName = $safeName . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
                $uploadDirectory = $this->getParameter('kernel.project_dir') . '/public/assets';
                $savedImagePath = null;

                try {
                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir($uploadDirectory, 0775, true) &&
                        !is_dir($uploadDirectory)
                    ) {
                        throw new \RuntimeException('Could not create the image directory.');
                    }

                    $image->move($uploadDirectory, $fileName);
                    $savedImagePath = $uploadDirectory . '/' . $fileName;

                    $connection->insert('burgers', [
                        'name' => $form['name'],
                        'description' => $form['description'],
                        'price' => (float) $form['price'],
                        'image' => $fileName,
                        'category' => $form['category'],
                        'available' => (int) $form['available'],
                        'stock_quantity' => (int) $form['stock_quantity'],
                    ]);

                    $this->addFlash('success', 'Burger added successfully!');

                    return $this->redirectToRoute('admin_burgers');
                } catch (\Throwable $exception) {
                    if ($savedImagePath !== null && is_file($savedImagePath)) {
                        @unlink($savedImagePath);
                    }

                    $error = 'Unable to save the burger. Check the database and image folder permissions.';

                    if ($this->getParameter('kernel.environment') === 'dev') {
                        $error .= ' Details: ' . $exception->getMessage();
                    }
                }
            }
        }

        return $this->render('Admin/add_burger.html.twig', [
            'admin_username' => 'Admin',
            'form' => $form,
            'error' => $error,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RESTOCK BURGER
    |--------------------------------------------------------------------------
    */

    #[Route('/admin/burgers/restock', name: 'admin_burger_restock', methods: ['GET', 'POST'])]
    public function restock(Request $request, Connection $connection): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $error = '';
        $success = '';

        $selectedId = (int) $request->request->get(
            'burger_id',
            $request->query->get('burger_id', 0)
        );

        $addStock = trim((string) $request->request->get('add_stock', ''));

        if ($request->isMethod('POST') && $request->request->has('restock')) {
            if ($selectedId <= 0) {
                $error = 'Please select a burger.';
            } elseif ($addStock === '' || !ctype_digit($addStock)) {
                $error = 'Please enter a valid stock quantity.';
            } elseif ((int) $addStock <= 0) {
                $error = 'The stock to add must be greater than 0.';
            } else {
                try {
                    $connection->beginTransaction();

                    $burger = $connection->fetchAssociative(
                        'SELECT id, name, stock_quantity
                         FROM burgers
                         WHERE id = ?
                         FOR UPDATE',
                        [$selectedId]
                    );

                    if (!$burger) {
                        $connection->rollBack();
                        $error = 'The selected burger could not be found.';
                    } else {
                        $currentStock = (int) $burger['stock_quantity'];
                        $quantityToAdd = (int) $addStock;

                        if (
                            $quantityToAdd > PHP_INT_MAX - $currentStock ||
                            $quantityToAdd > 2147483647 - $currentStock
                        ) {
                            $connection->rollBack();
                            $error = 'The resulting stock quantity is too large.';
                        } else {
                            $connection->executeStatement(
                                'UPDATE burgers
                                 SET stock_quantity = stock_quantity + ?
                                 WHERE id = ?',
                                [$quantityToAdd, $selectedId]
                            );

                            $newStock = $currentStock + $quantityToAdd;

                            $connection->commit();

                            $success = sprintf(
                                '%s has been restocked successfully. Stock increased from %d to %d.',
                                $burger['name'],
                                $currentStock,
                                $newStock
                            );

                            $addStock = '';
                        }
                    }
                } catch (\Throwable $exception) {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }

                    $error = 'Failed to update stock. Please check the database and try again.';

                    if ($this->getParameter('kernel.environment') === 'dev') {
                        $error .= ' Details: ' . $exception->getMessage();
                    }
                }
            }
        }

        $burgers = $connection->fetchAllAssociative(
            'SELECT id, name, category, price, image, stock_quantity, available
             FROM burgers
             ORDER BY name ASC'
        );

        $selectedBurger = null;

        if ($selectedId > 0) {
            $selectedBurger = $connection->fetchAssociative(
                'SELECT id, name, category, price, image, stock_quantity, available
                 FROM burgers
                 WHERE id = ?',
                [$selectedId]
            );
        }

        return $this->render('Admin/restock_burgers.html.twig', [
            'admin_username' => 'Admin',
            'burgers' => $burgers,
            'selected_burger' => $selectedBurger ?: null,
            'selected_burger_id' => $selectedId,
            'add_stock' => $addStock,
            'error' => $error,
            'success' => $success,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT BURGER
    |--------------------------------------------------------------------------
    */

    #[Route(
        '/admin/burgers/{id}/edit',
        name: 'admin_burger_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function edit(
        int $id,
        Request $request,
        Connection $connection
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $burger = $connection->fetchAssociative(
            'SELECT id, name, description, price, image, category,
                    available, stock_quantity
             FROM burgers
             WHERE id = ?',
            [$id]
        );

        if (!$burger) {
            throw $this->createNotFoundException('Burger not found.');
        }

        $error = '';

        $form = [
            'name' => $burger['name'],
            'description' => $burger['description'],
            'price' => (string) $burger['price'],
            'category' => $burger['category'],
            'available' => (int) $burger['available'] === 1,
        ];

        // Keep the categories from the original Edit Burger page.
        $categories = [
            'Signature Burgers',
            'Classic Burgers',
            'Special Burgers',
            'Chicken Burgers',
        ];

        if ($request->isMethod('POST')) {
            $form['name'] = trim((string) $request->request->get('name', ''));
            $form['description'] = trim((string) $request->request->get('description', ''));
            $form['price'] = trim((string) $request->request->get('price', ''));
            $form['category'] = trim((string) $request->request->get('category', ''));
            $form['available'] = $request->request->has('available');

            $image = $request->files->get('image');

            $hasNewImage = $image instanceof UploadedFile
                && $image->getError() !== \UPLOAD_ERR_NO_FILE;

            if ($form['name'] === '') {
                $error = 'Please enter a burger name.';
            } elseif ($form['description'] === '') {
                $error = 'Please enter a burger description.';
            } elseif (
                $form['price'] === '' ||
                !is_numeric($form['price']) ||
                !is_finite((float) $form['price']) ||
                (float) $form['price'] < 0
            ) {
                $error = 'Please enter a valid price.';
            } elseif (!in_array($form['category'], $categories, true)) {
                $error = 'Please select a valid category.';
            } elseif ($hasNewImage && !$image->isValid()) {
                $error = 'There was a problem uploading the image.';
            } elseif (
                $hasNewImage &&
                !in_array($image->getMimeType(), [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                ], true)
            ) {
                $error = 'Invalid image type. Please use JPG, JPEG, PNG, or WEBP.';
            } elseif ($hasNewImage && $image->getSize() > 5 * 1024 * 1024) {
                $error = 'Image is too large. Maximum file size is 5 MB.';
            } else {
                $newFileName = $burger['image'];
                $newImagePath = null;
                $uploadDirectory = $this->getParameter('kernel.project_dir') . '/public/assets';

                try {
                    if ($hasNewImage) {
                        $extensionMap = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                        ];

                        $extension = $extensionMap[$image->getMimeType()];

                        $safeName = preg_replace(
                            '/[^a-z0-9-]/',
                            '-',
                            strtolower($form['name'])
                        );

                        $safeName = trim($safeName, '-');

                        if ($safeName === '') {
                            $safeName = 'burger';
                        }

                        $newFileName = $safeName . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0775, true) &&
                            !is_dir($uploadDirectory)
                        ) {
                            throw new \RuntimeException('Could not create the image directory.');
                        }

                        $image->move($uploadDirectory, $newFileName);
                        $newImagePath = $uploadDirectory . '/' . $newFileName;
                    }

                    // Intentionally do not update stock_quantity here.
                    $connection->update('burgers', [
                        'name' => $form['name'],
                        'description' => $form['description'],
                        'price' => (float) $form['price'],
                        'image' => $newFileName,
                        'category' => $form['category'],
                        'available' => $form['available'] ? 1 : 0,
                    ], [
                        'id' => $id,
                    ]);

                    // Remove the old image only after the database update succeeds.
                    if (
                        $newImagePath !== null &&
                        !empty($burger['image'])
                    ) {
                        $oldImagePath = $uploadDirectory . '/' . basename($burger['image']);

                        if (
                            is_file($oldImagePath) &&
                            $oldImagePath !== $newImagePath
                        ) {
                            @unlink($oldImagePath);
                        }
                    }

                    $this->addFlash('success', 'Burger updated successfully!');

                    return $this->redirectToRoute('admin_burgers');
                } catch (\Throwable $exception) {
                    if ($newImagePath !== null && is_file($newImagePath)) {
                        @unlink($newImagePath);
                    }

                    $error = 'Unable to update the burger. Please check the database and image folder permissions.';

                    if ($this->getParameter('kernel.environment') === 'dev') {
                        $error .= ' Details: ' . $exception->getMessage();
                    }
                }
            }
        }

        return $this->render('Admin/edit_burger.html.twig', [
            'admin_username' => 'Admin',
            'burger' => $burger,
            'form' => $form,
            'categories' => $categories,
            'error' => $error,
        ]);
    }
    
#[Route('/admin/burgers/{id}/delete', name: 'admin_burger_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
public function delete(
    int $id,
    \Symfony\Component\HttpFoundation\Request $request,
    \Doctrine\DBAL\Connection $connection
): \Symfony\Component\HttpFoundation\RedirectResponse {
    $this->denyAccessUnlessGranted('ROLE_ADMIN');

    if (!$this->isCsrfTokenValid(
        'delete_burger_' . $id,
        $request->request->get('_token')
    )) {
        $this->addFlash('error', 'Invalid security token. Please try again.');

        return $this->redirectToRoute('admin_burgers');
    }

    try {
        $burger = $connection->fetchAssociative(
            'SELECT id, name, image FROM burgers WHERE id = ?',
            [$id]
        );

        if (!$burger) {
            $this->addFlash('error', 'Burger not found.');

            return $this->redirectToRoute('admin_burgers');
        }

        $connection->delete('burgers', ['id' => $id]);

        $image = $burger['image'] ?? '';

        if ($image !== '') {
            $imagePath = $this->getParameter('kernel.project_dir')
                . '/public/assets/'
                . basename($image);

            if (is_file($imagePath) && !@unlink($imagePath)) {
                $this->addFlash(
                    'warning',
                    'Burger deleted, but its image could not be removed.'
                );

                return $this->redirectToRoute('admin_burgers');
            }
        }

        $this->addFlash('success', 'Burger deleted successfully.');
    } catch (\Throwable $exception) {
        $this->addFlash(
            'error',
            'Unable to delete the burger. It may be linked to another record.'
        );
    }

    return $this->redirectToRoute('admin_burgers');
}

}
