<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MasterAddress;
use App\Models\AddressDiscrepancy;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function needsReview()
    {
        $rows = AddressDiscrepancy::with('masterAddress')
            ->where('requires_admin_validation', true)
            ->orderByDesc('last_discrepancy_at')
            ->limit(100)
            ->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function overrideCoordinate(int $id, Request $request)
    {
        $request->validate([
            'final_navigation_coordinate_lat' => ['required','numeric'],
            'final_navigation_coordinate_lng' => ['required','numeric'],
        ]);

        $addr = MasterAddress::findOrFail($id);
        $addr->final_navigation_coordinate_lat = $request->float('final_navigation_coordinate_lat');
        $addr->final_navigation_coordinate_lng = $request->float('final_navigation_coordinate_lng');
        $addr->save();

        return response()->json(['success' => true, 'data' => $addr]);
    }



    // public function overrideAddress(int $id, \Illuminate\Http\Request $request) {
    //             $request->validate([
    //                 'final_navigation_coordinate_lat' => ['required','numeric','between:-90,90'],
    //                 'final_navigation_coordinate_lng' => ['required','numeric','between:-180,180'],
    //             ]);
    //             $addr = \App\Models\MasterAddress::findOrFail($id);
    //             $addr->final_navigation_coordinate_lat = $request->float('final_navigation_coordinate_lat');
    //             $addr->final_navigation_coordinate_lng = $request->float('final_navigation_coordinate_lng');
    //             $addr->save();
    //             return response()->json(['success'=>true,'data'=>$addr]);
    //         }



}
