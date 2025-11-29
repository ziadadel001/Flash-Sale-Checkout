<?php

namespace App\Traits;

trait ApiResponse
{
    protected function success($data = [], $code = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $code);
    }

    protected function error($message, $code = 400, $errorCode = null)
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $errorCode,
            ]
        ], $code);
    }
}
