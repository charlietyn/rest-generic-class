<?php
/**Generate by ASGENS
 *@author Charlietyn
 */

namespace Ronu\Core\Controllers\RestGenericClass;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RestController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $modelClass = "";


    /** @var Services $service */
    protected $service = "";

    public function callAction($method, $parameters)
    {
        $log = env('LOG_QUERY', false);
        if ($log)
            File::append(
                storage_path('/logs/query.log'),
                PHP_EOL . PHP_EOL . '--------Calling action ' . get_class($this) . ' method  ' . $method . ' ' . date('Y-m-d h:m:s a') . '------------' . PHP_EOL
            );
        return parent::callAction($method, $parameters); // TODO: Change the autogenerated stub
    }

    /**
     * Display a listing of the resource.
     * @return []
     */
public function process_request(Request $request):array
    {
        $parameters = [];
        $payloads=array_merge($request->query(),$request->request->all());
        array_key_exists('relations', $payloads) ? $parameters['relations'] = $request['relations'] : $parameters['relations'] = null;
        array_key_exists('soft_delete', $payloads) ? $parameters['soft_delete'] = $request['soft_delete'] : $parameters['soft_delete'] = null;
        array_key_exists('attr', $payloads) ? $parameters['attr'] = $request['attr'] : $parameters['attr'] = null;
        array_key_exists('eq', $payloads) ? $parameters['attr'] = $request['eq'] : false;
        array_key_exists('select', $payloads) ? $parameters['select'] = $request['select'] : $parameters['select'] = "*";
        array_key_exists('pagination', $payloads) ? $parameters['pagination'] = $request['pagination'] : $parameters['pagination'] = null;
        array_key_exists('orderby', $payloads) ? $parameters['orderby'] = $request['orderby'] : $parameters['orderby'] = null;
        array_key_exists('oper', $payloads) ? $parameters['oper'] = $request['oper'] : $parameters['oper'] = null;

        return $parameters;
    }

    public function index(Request $request):LengthAwarePaginator|array
    {
        $params = $this->process_request($request);
        $result = $this->service->list_all($params);
        return $result;
    }

    /**
     * Display a listing of the resource.
     * @return array
     */
    public function actionValidate(BaseFormRequest $request):JsonResponse
    {
        return response()->json(['success'=>true], 200);;
    }

    public function store(BaseFormRequest $request):array
    {
        DB::beginTransaction();
        try {
            $params = $request->all();
            $result = $this->service->create($params);
            if ($result['success'])
                DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return $result;
    }

    public function update(Request $request, $id):array
    {
        DB::beginTransaction();
        try {
            $params = $request->all();
            $result = $this->service->update($params, $id);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return $result;
    }

    public function updateMultiple(Request $request):array
    {
        DB::beginTransaction();
        try {
            $params = $request->all();
            $result = $this->service->update_multiple($params);
            if ($result['success'])
                DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return $result;
    }

    public function show(Request $request, $id):BaseModel
    {
        $params = $this->process_request($request);
        return $this->service->show($params, $id);
    }

    public function destroy($id):array
    {
        DB::beginTransaction();
        try {
            $result = $this->service->destroy($id);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return $result;
    }

    public function deleteById(Request $request):array
    {
        $params = $request->all();
        DB::beginTransaction();
        try {
            $result = $this->service->destroybyid($params);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return $result;
    }

    public function export_excel(Request $request)
    {
        $params = $this->process_request($request);
        return $this->service->exportExcel($params);
    }

    public function export_pdf(Request $request)
    {
        $params = $this->process_request($request);
        return $this->service->exportPdf($params);
    }
}





