<?php

namespace App\Http\Controllers\Admin;

use App\Models\Zone;
use App\Models\Module;
use App\Exports\ZoneExport;
use App\Models\Translation;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Maatwebsite\Excel\Facades\Excel;
use Rap2hpoutre\FastExcel\FastExcel;
use App\Models\Module as ModelsModule;
Use MatanYadaev\EloquentSpatial\Objects\Polygon;
use Illuminate\Support\Facades\Validator;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\LineString;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $key = explode(' ', $request['search']);

        $zones = Zone::withCount(['stores','deliverymen'])

        ->when(isset($key) , function($q) use($key){
            $q->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('name', 'like', "%{$value}%");
                }
            });
        })
        ->latest()->paginate(config('default_pagination'));

        return view('admin-views.zone.index', compact('zones'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:zones|max:191',
            'coordinates' => 'required',
            'name.0' => 'required',
        ],[
            'name.0.required'=>translate('default_name_is_required'),
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }
        $value = $request->coordinates;
        foreach(explode('),(',trim($value,'()')) as $index=>$single_array){
            if($index == 0)
            {
                $lastcord = explode(',',$single_array);
            }
            $coords = explode(',',$single_array);
            $polygon[] = new Point($coords[0], $coords[1]);
        }
        $zone_id=Zone::all()->count() + 1;
        $polygon[] = new Point($lastcord[0], $lastcord[1]);
        $zone = new Zone();
        $zone->name = $request->name[array_search('default', $request->lang)];
        $zone->coordinates = new Polygon([new LineString($polygon)]);
        $zone->store_wise_topic =  'zone_'.$zone_id.'_store';
        $zone->customer_wise_topic = 'zone_'.$zone_id.'_customer';
        $zone->deliveryman_wise_topic = 'zone_'.$zone_id.'_delivery_man';
        $zone->cash_on_delivery = $request->cash_on_delivery?1:0;
        $zone->digital_payment = $request->digital_payment?1:0;
        $zone->save();

        $default_lang = str_replace('_', '-', app()->getLocale());
        $data = [];
        foreach($request->lang as $index=>$key)
        {
            if($default_lang == $key && !($request->name[$index])){
                if($key != 'default')
                {
                    array_push($data, Array(
                        'translationable_type'  => 'App\Models\Zone',
                        'translationable_id'    => $zone->id,
                        'locale'                => $key,
                        'key'                   => 'name',
                        'value'                 => $zone->name,
                    ));
                }
            }else{

                if($request->name[$index] && $key != 'default')
                {
                    array_push($data, Array(
                        'translationable_type'  => 'App\Models\Zone',
                        'translationable_id'    => $zone->id,
                        'locale'                => $key,
                        'key'                   => 'name',
                        'value'                 => $request->name[$index],
                    ));
                }
            }
        }

        if(count($data))
        {
            Translation::insert($data);
        }

        // Toastr::success(translate('messages.zone_added_successfully'));
        // return back();
        $zones = Zone::withCount(['stores','deliverymen'])->latest()->paginate(config('default_pagination'));
        return response()->json([
            'view'=>view('admin-views.zone.partials._table',compact('zones'))->render(),
            'id'=>$zone->id,
            'total'=>$zones->count()
        ]);
    }

    public function edit($id)
    {
        if(env('APP_MODE')=='demo' && $id == 1)
        {
            Toastr::warning(translate('messages.you_can_not_edit_this_zone_please_add_a_new_zone_to_edit'));
            return back();
        }
        $zone=Zone::selectRaw("*,ST_AsText(ST_Centroid(`coordinates`)) as center")->findOrFail($id);

        $area = json_decode($zone->coordinates[0]->toJson(),true);
        return view('admin-views.zone.edit', compact(['zone','area']));
    }

    public function module_setup($id)
    {
        $zone=Zone::with('modules')->findOrFail($id);
        return view('admin-views.zone.module-setup', compact('zone'));
    }

    public function go_module_setup()
    {
        $zone=Zone::with('modules')->latest()->first();
        return view('admin-views.zone.module-setup', compact('zone'));
    }

    public function instruction()
    {
        session()->put('zone-instruction', 1);
        $zones = Zone::withCount(['stores','deliverymen'])->latest()->paginate(config('default_pagination'));
        return view('admin-views.zone.index', compact('zones'));
    }

    public function module_update(Request $request, $id)
    {

        $request->validate([
            'cash_on_delivery' => 'required_without:digital_payment',
            'digital_payment' => 'required_without:cash_on_delivery',
            'increased_delivery_fee' => 'nullable|numeric|between:0,999.99|required_if:increased_delivery_fee_status,1',
        ], [
            'increased_delivery_fee.required_if' => translate('messages.increased_delivery_fee_is_required')
        ]);

        foreach($request->module_data as $data){
            if(isset($data['maximum_shipping_charge']) && ((int)$data['maximum_shipping_charge'] < (int)$data['minimum_shipping_charge'])){
                Toastr::error(translate('Maximum delivery charge must be greater than minimum delivery charge.'));
                return back();
            }
        }
        $zone=Zone::findOrFail($id);
        $zone->cash_on_delivery = $request->cash_on_delivery?1:0;
        $zone->digital_payment = $request->digital_payment?1:0;
        $zone->offline_payment = $request->offline_payment?1:0;

        $zone->increased_delivery_fee = $request->increased_delivery_fee ?? 0;
        $zone->increased_delivery_fee_status = $request->increased_delivery_fee_status ?? 0;
        $zone->increase_delivery_charge_message = $request->increase_delivery_charge_message ?? null;
        
        $zone->modules()->sync($request->module_data);
        $zone->save();
        Toastr::success(translate('messages.zone_module_updated_successfully'));
        return redirect()->route('admin.business-settings.zone.home');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|max:191|unique:zones,name,'.$id,
            'coordinates' => 'required',
            'name.0' => 'required',
        ],[
            'name.0.required'=>translate('default_name_is_required'),
        ]);
        $value = $request->coordinates;
        foreach(explode('),(',trim($value,'()')) as $index=>$single_array){
            if($index == 0)
            {
                $lastcord = explode(',',$single_array);
            }
            $coords = explode(',',$single_array);
            $polygon[] = new Point((float)$coords[0], (float)$coords[1]);
        }
        $polygon[] = new Point((float)$lastcord[0], (float)$lastcord[1]);
        $zone=Zone::findOrFail($id);
        $zone->name = $request->name[array_search('default', $request->lang)];
        $zone->store_wise_topic =  'zone_'.$id.'_store';
        $zone->customer_wise_topic = 'zone_'.$id.'_customer';
        $zone->deliveryman_wise_topic = 'zone_'.$id.'_delivery_man';
        $zone->cash_on_delivery = $request->cash_on_delivery?1:0;
        $zone->digital_payment = $request->digital_payment?1:0;
        $zone->save();
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach($request->lang as $index=>$key)
        {
            if($default_lang == $key && !($request->name[$index])){
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Zone',
                            'translationable_id' => $zone->id,
                            'locale' => $key,
                            'key' => 'name'
                        ],
                        ['value' => $zone->name]
                    );
                }
            }else{

                if ($request->name[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        ['translationable_type'  => 'App\Models\Zone',
                            'translationable_id'    => $zone->id,
                            'locale'                => $key,
                            'key'                   => 'name'],
                        ['value'                 => $request->name[$index]]
                    );
                }
            }
        }

        try{
            $zone->coordinates = new Polygon([new LineString($polygon)]);
            $zone->save();
        } catch (\Exception $ex) {
        }
        Toastr::success(translate('messages.zone_updated_successfully'));
        return redirect()->route('admin.business-settings.zone.home');
    }

    public function destroy(Zone $zone)
    {
        if(env('APP_MODE')=='demo' && $zone->id == 1)
        {
            Toastr::warning(translate('messages.you_can_not_delete_this_zone_please_add_a_new_zone_to_delete'));
            return back();
        }
        $zone->delete();
        Toastr::success(translate('messages.zone_deleted_successfully'));
        return back();
    }

    public function status(Request $request)
    {
        if(env('APP_MODE')=='demo' && $request->id == 1)
        {
            Toastr::warning('Sorry!You can not inactive this zone!');
            return back();
        }
        $zone = Zone::findOrFail($request->id);
        $zone->status = $request->status;
        $zone->save();
        Toastr::success(translate('messages.zone_status_updated'));
        return back();
    }

    public function digital_payment(Request $request)
    {
        $zone = Zone::findOrFail($request->id);
        $zone->digital_payment = $request->digital_payment;
        $zone->save();
        Toastr::success(translate('messages.zone_digital_payment_status_updated'));
        return back();
    }

    public function cash_on_delivery(Request $request)
    {
        $zone = Zone::findOrFail($request->id);
        $zone->cash_on_delivery = $request->cash_on_delivery;
        $zone->save();
        Toastr::success(translate('messages.zone_cash_on_delivery_status_updated'));
        return back();
    }

    public function offline_payment(Request $request)
    {
        $zone = Zone::findOrFail($request->id);
        $zone->offline_payment = $request->offline_payment;
        $zone->save();
        Toastr::success(translate('messages.zone_offline_payment_status_updated'));
        return back();
    }

    public function search(Request $request){
        $key = explode(' ', $request['search']);
        $zones=Zone::withCount(['stores','deliverymen'])->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })->limit(50)->get();
        return response()->json([
            'view'=>view('admin-views.zone.partials._table',compact('zones'))->render(),
            'total'=>$zones->count()
        ]);
    }

    public function get_coordinates($id){
        $zone=Zone::withoutGlobalScopes()->selectRaw("*,ST_AsText(ST_Centroid(`coordinates`)) as center")->findOrFail($id);
        $area = json_decode($zone->coordinates[0]->toJson(),true);
        $data = Helpers::format_coordiantes($area['coordinates']);
        $center = (object)['lat'=>(float)trim(explode(' ',$zone->center)[1], 'POINT()'), 'lng'=>(float)trim(explode(' ',$zone->center)[0], 'POINT()')];
        return response()->json(['coordinates'=>$data, 'center'=>$center]);
    }

    public function zone_filter($id)
    {
        if($id == 'all')
        {
            if(session()->has('zone_id')){
                session()->forget('zone_id');
            }
        }
        else{
            session()->put('zone_id', $id);
        }

        return back();
    }

    public function get_all_zone_cordinates($id = 0)
    {
        $zones = Zone::where('id', '<>', $id)->active()->get();
        $data = [];
        foreach($zones as $zone)
        {
            $area = json_decode($zone->coordinates[0]->toJson(),true);
            $data[] = Helpers::format_coordiantes($area['coordinates']);
        }
        return response()->json($data,200);
    }

    public function export(Request $request ,$type){
        $key = explode(' ', $request['search']);

        $collection = Zone::withCount(['stores','deliverymen'])
        ->when(isset($key) , function($q) use($key){
            $q->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('name', 'like', "%{$value}%");
                }
            });
        })
        ->get();
        $data=[
            'data' =>$collection,
            'search' =>$request['search'] ?? null,
        ];
        if($type == 'csv'){
            return Excel::download(new ZoneExport($data), 'Zone.csv');
        }
        return Excel::download(new ZoneExport($data), 'Zone.xlsx');
    }
}
