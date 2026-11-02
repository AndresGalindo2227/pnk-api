<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use Pnk\Http\HttpException;
use Pnk\Http\Request;
use Pnk\Http\Response;

/** HU-09: receipt photo of the transfer, stored outside the public folder. */
final class PaymentsController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const ALLOWED_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function uploadReceipt(Request $request): Response
    {
        $playerId = $this->playerId($request);
        $bookingId = (int) $request->param('id');

        $stmt = $this->db->prepare("SELECT id, status FROM bookings WHERE id = :id AND player_id = :p");
        $stmt->execute(['id' => $bookingId, 'p' => $playerId]);
        $booking = $stmt->fetch() ?: throw HttpException::notFound('Booking');
        if ($booking['status'] !== 'pending') {
            throw HttpException::conflict('PAYMENT_ALREADY_REVIEWED', 'This booking does not need a receipt.');
        }

        $file = $_FILES['photo'] ?? null;
        $amount = (int) ($_POST['amount'] ?? 0);
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw HttpException::validation([['field' => 'photo', 'issue' => 'is required']]);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new HttpException(413, 'FILE_TOO_LARGE', 'The photo is larger than 2 MB.');
        }
        // Trust the bytes, not the file name or the Content-Type sent by the client.
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::ALLOWED_TYPES[$type])) {
            throw HttpException::validation([['field' => 'photo', 'issue' => 'must be a JPEG or PNG image']]);
        }
        if ($amount <= 0) {
            throw HttpException::validation([['field' => 'amount', 'issue' => 'must be greater than zero']]);
        }

        $name = sprintf('%d-%s.%s', $bookingId, bin2hex(random_bytes(8)), self::ALLOWED_TYPES[$type]);
        if (!move_uploaded_file($file['tmp_name'], rtrim($this->config['receipts_dir'], '/') . '/' . $name)) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'The receipt could not be stored.');
        }

        $this->db->prepare("INSERT INTO payments (booking_id, method, amount, status, receipt_path) VALUES (:b, 'transfer_receipt', :a, 'pending', :r)")
            ->execute(['b' => $bookingId, 'a' => $amount, 'r' => $name]);
        $id = (int) $this->db->lastInsertId();

        return Response::json([
            'id' => $id,
            'bookingId' => $bookingId,
            'method' => 'transfer_receipt',
            'amount' => $amount,
            'status' => 'pending',
            'receiptUrl' => null,
            'rejectionReason' => null,
            'createdAt' => self::iso(self::now()),
        ], 201);
    }
}
