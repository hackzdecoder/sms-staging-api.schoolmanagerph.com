<?php

namespace App\Http\Controllers\api\trademarks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrademarksController extends Controller
{
    protected string $connectionName = 'trademarks';

    public function getTrademarksInfo(Request $request) 
    {
        try {
            $type = $request->input('type', 'trademarks');
            
            if ($type === 'company') {
                // Fetch company info - ADD publication_date to select
                $company = DB::connection($this->connectionName)
                    ->table('companies')
                    ->select('id', 'copyright_name', 'company_name', 'publication_date', 'created_at', 'updated_at')
                    ->first();
                
                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Company information not found'
                    ], 404);
                }
                
                return response()->json([
                    'success' => true,
                    'type' => 'company',
                    'data' => $company
                ]);
            }
            else {
                // Your existing trademarks logic
                return response()->json([
                    'success' => true,
                    'type' => 'trademarks',
                    'data' => []
                ]);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}