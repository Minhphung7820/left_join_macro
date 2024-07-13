<?php

namespace App\Module\Warehouse\Repository;

use App\Helpers\Activity;
use App\Module\CRM\Model\Customer\Customer;
use App\Module\Warehouse\Helpers\Helper;
use App\Module\CRM\Util\BusinessUtil;
use App\Module\CRM\Util\ModuleUtil;
use App\Module\CRM\Util\ProductUtil;
use App\Module\Sale\Model\TransactionSellLine as ModelTransactionSellLine;
use App\Module\Warehouse\Model\ActivityHistory;
use App\Module\Warehouse\Model\Brands;
use App\Module\Warehouse\Model\Category;
use App\Module\Warehouse\Model\Contact;
use App\Module\Warehouse\Model\Product;
use App\Module\Warehouse\Model\ProductImage;
use App\Module\Warehouse\Model\ProductVariation;
use App\Module\Warehouse\Model\PurchaseLine;
use App\Module\Warehouse\Model\Stock;
use App\Module\Warehouse\Model\StockProduct;
use App\Module\Warehouse\Model\Transaction;
use App\Module\Warehouse\Model\TransactionSellLine;
use App\Module\Warehouse\Model\Unit;
use App\Module\Warehouse\Model\Variant\Attribute;
use App\Module\Warehouse\Model\Variant\ProductParent;
use App\Module\Warehouse\Model\Variation;
use App\Module\Warehouse\Model\VariationLocationDetails;
use DateTime;
use Illuminate\Support\Facades\DB;
use Package\Util\BasicEntity;
use Package\Repository\RepositoryInterface;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Package\Exception\HttpException;
use Maatwebsite\Excel\Facades\Excel;
use Package\Exception\DatabaseException;
use stdClass;

class ProductRepository extends BasicEntity implements RepositoryInterface
{

  const NCC_DEFAULT = 'ncc_default';
  /**
   * All Utils instance.
   *
   */
  protected $productUtil;

  protected $moduleUtil;

  protected $businessUtil;

  protected $dummyPaymentLine;

  private $barcode_types;

  public $table;

  public $primaryKey;

  public $fillable;

  public $hidden;

  public $arrayProductIdsExcept = [];
  public function __construct()
  {
    $brand = new Brands();
    $this->table = $brand->getTable();
    $this->fillable = $brand->getFillable();
    $this->hidden = $brand->getHidden();
    $this->primaryKey = $brand->getKeyName();
    array_push($this->fillable, $this->primaryKey);
    $this->productUtil = new ProductUtil();
    $this->moduleUtil = new ModuleUtil();
    $this->businessUtil = new BusinessUtil();

    //barcode types
    $this->barcode_types = $this->productUtil->barcode_types();

    $this->dummyPaymentLine = [
      'method' => 'cash', 'amount' => 0, 'note' => '', 'card_transaction_number' => '', 'card_number' => '', 'card_type' => '', 'card_holder_name' => '', 'card_month' => '', 'card_year' => '', 'card_security' => '', 'cheque_number' => '', 'bank_account_number' => '',
      'is_return' => 0, 'transaction_no' => ''
    ];
  }

  public function all($request)
  {
    try {
      $business_id = request()->header('business-id');
      $contact_id = $request->contact_id;
      $showAll = $request->showAll;
      $type = $request->type;

      if ($showAll == "false" && !empty($contact_id)) {
        if ($type === "purchase") {
          $data = $this->getListAllProduct($request, $business_id, $contact_id);
          return $this->summaryCount($data, $request->all());
        } else {
          $data = $this->getListProductByContact($request, $business_id, $contact_id);
          return $this->summaryCount($data, $request->all());
        }
      }

      $data = $this->getListAllProduct($request, $business_id);
      return $this->summaryCount($data, $request->all());
    } catch (\Exception $e) {
      $message = $e->getMessage();
      throw new HttpException($message, 500);
    }
  }

  public function summaryCount($data, $request = null)
  {
    $business_id = request()->header('business-id');
    $stock_id = request()->header('stock-id');
    $summary = DB::table('stock_products')
      ->leftJoin("products", function ($join) use ($stock_id) {
        $join->on('products.id', '=', 'stock_products.product_id');
      })
      ->leftJoin(
        'units',
        'units.id',
        '=',
        'products.unit_id'
      )
      ->leftJoin(
        'categories',
        'categories.id',
        '=',
        'products.category_id'
      )
      ->leftJoin(
        'brands',
        'brands.id',
        '=',
        'products.brand_id'
      )
      ->leftJoin(
        'crm_customers',
        'crm_customers.id',
        '=',
        'products.contact_id'
      )
      ->select(DB::raw('count(*) as total_count, status'))
      ->where('stock_id', $stock_id)
      ->where('products.business_id', $business_id);

    if (!empty($request['keyword'])) {
      $keyword = $request['keyword'];
      $summary->where('products.name', 'LIKE', "%$keyword%");
      $summary->orWhere('products.sku', 'LIKE', "%$keyword%");
    }

    if (!empty($request['start_date'])) {
      $start = DateTime::createFromFormat('d/m/Y', $request['start_date'])->format('Y-m-d');
      $summary->whereDate('products.created_at', '>=', $start);
    }

    if (!empty($request['end_date'])) {
      $end = DateTime::createFromFormat('d/m/Y', $request['end_date'])->format('Y-m-d');
      $summary->whereDate('products.created_at', '<=', $end);
    }
    if (!empty($request['contact_name'])) {
      $contactName = $request['contact_name'];
      $summary->where("crm_customers.business_name", "LIKE", "%$contactName%");
    }


    if (!empty($request['category'])) {
      $category = $request['category'];
      $summary->where("categories.name", "LIKE", "%$category%");
    }


    if (!empty($request['brand'])) {
      $brand = $request['brand'];
      $summary->where("brands.name", "LIKE", "%$brand%");
    }


    if (!empty($request['unit'])) {
      $unit = $request['unit'];
      $summary->where("units.actual_name", "LIKE", "%$unit%");
    }

    if (!empty($request['supplier_id'])) {
      $supplier_id = $request['supplier_id'];
      $summary->where("crm_customers.id", $supplier_id);
    }

    $summary->groupBy('status');
    $status = request()->get('status', null);
    if (!empty($status) && $status !== "all") {
      $summary->where('stock_products.status', '=', $status);
    }

    $summary = $summary->get();

    $totalCount = $summary->sum('total_count');
    $summary->push([
      'status' => 'all',
      'total_count' => $totalCount,
    ]);
    $response['data'] = $data;
    $response['summary'] = $summary;

    return $response;
  }

  public function find($id)
  {
    try {
      $business_id = request()->header('business-id');

      $product = Product::where('products.business_id', $business_id)
        ->where('products.id', $id)
        ->with([
          'product_images',
          'variations:id,product_id,name,sub_sku,description,image,barcode,allowSerial,status,default_sell_price,sell_price_inc_tax',
          'product_variations' => function ($query) {
            $query->orderBy('id', 'asc');
          },
          'stock_products',
          'stock_products.stock',
          "brand",
          "contact",
          "unit",
          "category",
          "warranty",
          "product_serial:id,product_id,serial,is_sell",
          'created_by',
          'variants.stock',
          'variants.variantFirst.variant',
          'combo'
        ])
        ->first();
      if ($product) {
        $logs = ActivityHistory::with(["created_by"])
          ->where("subject_type", "product")
          ->where("subject_id", $product->id)->get();
        $product->logs = $logs;
        //
        if (isset(request()->is_append_variant) && request()->is_append_variant == 1) {
          $this->appendsVariant($product);
          $this->appendsVariant($product, false);
        }
      }

      return $product;
    } catch (\Exception $e) {
      DB::rollBack();
      $message = $e->getMessage();

      throw new HttpException($message, 500);
    }
  }

  protected function appendsVariant(&$object, $getInCombo = true)
  {
    $field = 'variants';

    if (!$getInCombo) {
      $field = 'combo';
    }

    if ($object->type === 'variable') {
      $object->variants->map(function ($item) use ($object) {
        if (isset($object->stock_id) && isset($object->attribute_first_id)) {
          if (
            $item->product_id == $object->id
            && $object->stock_id == $item->stock_id
            && $object->attribute_first_id == $item->attribute_first_id
          ) {
            $item->unit_price = $object->unit_price;
            $item->unit_price_inc_tax = $object->unit_price_inc_tax;
          }
        }
        return $item;
      });
    }
    if (isset($object->{$field})) {
      $object->{$field}->map(function ($item) use ($getInCombo) {
        $item->append_variant = $this
          ->appendsProductNotStockDefault(
            $item,
            $getInCombo
          );
        $item->append_variant  = collect($item->append_variant)
          ->map(function ($itemSub) use ($item) {
            if ($item['id'] == $itemSub['id']) {
              $itemSub['unit_price'] = $item['unit_price'];
            }
            return $itemSub;
          });
        return $item;
      });
    }
  }

  protected function appendsProductNotStockDefault($item, $getInCombo)
  {
    $query =  StockProduct::with(['stock:id,stock_name,is_default', 'product:id,name,sku', 'variantFirst']);
    if ($getInCombo) {
      $product_id = $item->product_id;
      $dataGroupBy = ['attribute_first_id', 'stock_id', 'product_id'];
      $query->where('attribute_first_id', $item->attribute_first_id);
    } else {
      $product_id = $item->parent->id;
      $dataGroupBy = ['stock_id', 'product_id'];
      $query->where('attribute_first_id', $item->attribute_first_id);
    }
    return $query->where('product_id', $product_id)->whereHas('stock', function ($subQuery) {
      $subQuery->whereIn('is_default', [1, 0, null]);
    })->where('is_main_stock', false)->groupBy($dataGroupBy)->get()->map(function ($item) {
      $item->unit_price_inc_tax = $item->unit_price;
      return $item;
    })->toArray();
  }

  public function create($request)
  {
    try {
      return DB::transaction(function () use ($request) {
        $business_id = request()->header('business-id');
        $stock_id = request()->header('stock-id');
        $user_id = Auth::guard('api')->user()->id;
        $form_fields = [
          'contact_id',
          'name',
          'brand_id',
          'unit_id',
          'category_id',
          'tax',
          'type',
          'barcode_type',
          'sku',
          'barcode',
          'alert_quantity',
          'tax_type',
          'weight',
          'product_custom_field1',
          'product_custom_field2',
          'product_custom_field3',
          'product_custom_field4',
          'product_description',
          'sub_unit_ids',
          'thumbnail',
          'priceData',
          // 'listVariation',
          'images',
          'enable_sr_no',
          'variants',
          'combo',
          'have_variant'
        ];
        $type = $request->type;
        if ($request->have_variant == 0 && $type !== 'combo') {
          $type = 'single';
        }
        $product_details['type'] = $type;
        $module_form_fields = $this->moduleUtil->getModuleFormField('product_form_fields');
        if (!empty($module_form_fields)) {
          $form_fields = array_merge($form_fields, $module_form_fields);
        }
        $product_details = $request->only($form_fields);
        $statusStock = $request->status;

        $product_details['business_id'] = $business_id;
        $product_details['created_by'] = $user_id;

        $product_details['enable_stock'] = (!empty($request->input('enable_stock')) && $request->input('enable_stock') == 1) ? 1 : 0;
        $product_details['not_for_selling'] = (!empty($request->input('not_for_selling')) && $request->input('not_for_selling') == 1) ? 1 : 0;

        if (!empty($request->input('sub_category_id'))) {
          $product_details['sub_category_id'] = $request->input('sub_category_id');
        }

        if (empty($product_details['sku'])) {
          $product_details['sku'] = ' ';
        }

        //upload documen
        $thumbnail = $request->input('thumbnail');
        $product_details['image'] = !empty($thumbnail) ? str_replace(tera_url("/"), "", $thumbnail) : "";

        $product_details['warranty_id'] = !empty($request->input('warranty_id')) ? $request->input('warranty_id') : null;

        if (isset($product_details['enable_sr_no'])) {
          $product_details['enable_sr_no'] = $product_details['enable_sr_no'] == true ? 1 : 0;
        }
        $product = Product::create($product_details);

        if (!$product) {
          $message = "Không thể lưu dữ liệu";
          throw new DatabaseException($message, 503);
        }

        if (empty(trim($request->input('sku')))) {
          $sku = $this->productUtil->generateProductSku($product->id, $business_id, "SP");
          $product->sku = $sku;
          $product->save();
        }

        //Add product locations
        $product_locations = $request->input('product_locations');
        if (!empty($product_locations)) {
          $product->product_locations()->sync($product_locations);
        }


        // image
        if (isset($request->images)) {
          $requestImages = $request->input('images');
          if (is_array($requestImages)) {
            if (!empty($requestImages) && count($requestImages) > 0) {
              $dataProductImage = [];
              foreach ($requestImages as $item) {
                $urlImage = !empty($item["url"]) ? str_replace(tera_url("/"), "", $item["url"]) : "";
                $dataProductImage[] = [
                  'product_id' => $product->id,
                  'image' => str_replace("/thumb", "", $urlImage),
                  'thumb' => $urlImage,
                  'created_at' => now(),
                ];
              }

              $productImage = ProductImage::insert($dataProductImage);

              if (!$productImage) {
                $message = "Không thể lưu dữ liệu";
                throw new DatabaseException($message, 503);
              }
            }
          }
        }
        $status = "pending";
        if (!empty($statusStock)) {
          $status = $statusStock;
        }
        $idsStockValid = [];
        $priceData = $request->input('priceData');
        if (!empty($priceData) && count($priceData) > 0) {
          $dataProductStock = [];
          foreach ($priceData as $item) {
            if ($type === 'combo') {
              $unit_price = !empty($item["unit_price"]) ? $item["unit_price"] : 0;
              $combo_unit_price =   $unit_price + $this->getTotalItemCombo($product, request()->combo ?? []);
            } else {
              $combo_unit_price = !empty($item["unit_price"]) ? $item["unit_price"] : 0;
            }
            $dataProductStock = [
              'combo_unit_price' => $combo_unit_price,
              'product_id' => $product->id,
              'stock_id' => $item['stock_id'],
              'quantity' => !empty($item["quantity"]) ? $item["quantity"] : 0,
              'purchase_price' => !empty($item["purchase_price"]) ? $item["purchase_price"] : 0,
              'unit_price' => !empty($item["unit_price"]) ? $item["unit_price"] : 0,
              'sale_price' => !empty($item["sale_price"]) ? $item["sale_price"] : 0,
              'sale_price_max' => !empty($item["sale_price_max"]) ? $item["sale_price_max"] : 0,
              'status' => $status,
              'is_main_stock' => true,
              'created_at' => now(),
            ];
            $productImage = StockProduct::create($dataProductStock);
            $idsStockValid[] = $productImage->id;
          }


          if (!$productImage) {
            $message = "Không thể lưu dữ liệu";
            throw new DatabaseException($message, 503);
          }
        }

        $variations = $request->variations;
        if (!empty($variations) && count($variations) > 0) {
          $dataVariations = [];

          foreach ($variations as $item) {
            if (isset($item["is_new"]) && $item["is_new"] === true) {
              $dataProductVariations = [
                'name' => !empty($item["name"]) ? $item["name"] : "",
                'value' => !empty($item["value"]) ? $item["value"] : "",
                'product_id' => $product->id,
                'created_at' => now(),
              ];

              array_push($dataVariations, $dataProductVariations);
            }
          }

          if (count($dataVariations) > 0) {
            $variationResult = ProductVariation::insert($dataVariations);
            if (!$variationResult) {
              $message = "Không thể lưu dữ liệu";
              throw new DatabaseException($message, 503);
            }
          }
        }

        // $listVariation = $request->listVariation;

        // if (!empty($listVariation) && count($listVariation) > 0) {
        //     $dataListVariations = [];

        //     foreach ($listVariation as $item) {
        //         if (isset($item["is_new"]) && $item["is_new"] === true) {
        //             $urlImage = !empty($item["images"]) ? str_replace(tera_url("/"), "", $item["images"]) : "";

        //             $dataVariations = [
        //                 'name' => !empty($item["name"]) ? $item["name"] : "",
        //                 'description' => !empty($item["description"]) ? $item["description"] : "",
        //                 'allowSerial' => !empty($item["allowSerial"]) ? $item["allowSerial"] : 0,
        //                 'barcode' => !empty($item["barcode"]) ? $item["barcode"] : "",
        //                 'sub_sku' => !empty($item["sku"]) ? $item["sku"] : "",
        //                 'image' => !empty($item["images"]) ? $urlImage : "",
        //                 'status' => !empty($item["status"]) ? $item["status"] : "active",
        //                 'default_sell_price' => !empty($item["unit_price"]) ? $item["unit_price"] : 0,
        //                 'sell_price_inc_tax' => !empty($item["sale_price"]) ? $item["sale_price"] : 0,
        //                 'product_id' => $product->id,
        //                 'created_at' => now(),
        //             ];

        //             array_push($dataListVariations, $dataVariations);
        //         }
        //     }


        //     if (count($dataListVariations) > 0) {
        //         $variationResult = Variation::insert($dataListVariations);
        //         if (!$variationResult) {
        //             $message = "Không thể lưu dữ liệu";
        //             throw new DatabaseException($message, 503);
        //         }
        //     }
        // }
        //
        if ($type === 'variable') {
          $variants = $request->variants ?? [];
          $idsStockValid = array_merge($this->saveVariants($product, $variants, $status), $idsStockValid);
          $this->removeVariantsStockExcept($product->id, $idsStockValid);
        }
        if ($type === 'combo') {
          $combo = $request->combo ?? [];
          $this->saveCombo($product, $combo);
        }
        //
        $message = "[$product->sku]-[" . $request->input("name") . "]";

        Activity::activityLog($message, $product->id, "crm_product", "created", $user_id);


        return $product;
      });
    } catch (HttpException $e) {

      $message = $e->getMessage();

      throw new HttpException($message, 500);
    }
  }

  private function removeVariantsStockExcept($product_id, $ids)
  {
    return StockProduct::where('product_id', $product_id)->whereNotIn('id', $ids)->delete();
  }

  private function saveVariants($product, $variants = [], $status)
  {
    $variantIds = [];
    if (!empty($variants) && $product) {
      $variant_edited = [];
      $variants = array_map(function ($item) use ($product, $status) {
        $item['product_id'] = $product->id;
        $item['is_main_stock'] = false;
        $item['status'] = $status;
        $item['created_at'] = now();
        return $item;
      }, $variants);

      foreach ($variants as $key => $variant) {

        $checkVariant = StockProduct::where([
          'product_id' => $variant['product_id'],
          'stock_id' => $variant['stock_id'],
          'attribute_first_id' => $variant['attribute_first_id'],
          'is_main_stock' => false
        ])->first();
        if ($checkVariant) {
          $this->checkUniqueSku($variant, $product, $this->generateUniqueString(), $checkVariant->id);
          $checkVariant->update($variant);
        } else {
          $this->checkUniqueSku($variant, $product,  $this->generateUniqueString(), null);
          $checkVariant = StockProduct::create($variant);
        }
        //
        $variantIds[] = $checkVariant->id;
      }
      $variants_first = Attribute::whereIn('id', array_column($variants, 'attribute_first_id'))->pluck('variant_id')->first();
      $variant_edited[] = [
        'id' => $variants_first,
        'attributes' => array_column($variants, 'attribute_first_id')
      ];
      //
      $product->variants_edited =   $variant_edited;
      $product->save();
    }
    return $variantIds;
  }

  private function checkUniqueSku(&$variant, $product, $subCodeGenerate = '', $except = null)
  {
    if (!empty($variant['sku'])) {
      $checkSkuExist = $this->checkSkuVariableExist($variant['sku'], $except);
      if ($checkSkuExist) {
        throw new HttpException("Mã biến thể : " . $variant['sku'] . " đã tồn tại !", 500, []);
      }
    } else {
      $sku = $this->productUtil->generateProductSku($product->id, request()->header('business-id'), "SP");
      $variant['sku'] = $sku . "-" . $subCodeGenerate;
    }
  }

  private function checkSkuVariableExist($sku, $except = null)
  {
    $query = DB::table('stock_products')->leftJoin('stocks', 'stocks.id', '=', 'stock_products.stock_id')->where('business_id', request()->header('business-id'))->where('stock_products.sku', trim($sku))->where('stock_products.is_main_stock', 0);
    if (!is_null($except)) {
      $query->where('stock_products.id', '!=', $except);
    }
    $data = $query->first();

    return $data;
  }

  private  function generateUniqueString($length = 6)
  {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    // Generate a string of 5 uppercase letters
    for ($i = 0; $i < $length; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }

    // Append a random number to the string
    $randomNumber = rand(1000, 9999);
    $uniqueString = $randomString . $randomNumber;

    return $uniqueString;
  }

  private function saveCombo($product, $combos = [])
  {
    if (!empty($combos) && $product) {
      $productParentIds = [];
      $arrayNullStock = array_filter($combos, function ($item) {
        return !isset($item['stock_id']) && $item['type'] !== 'variable';
      });

      if (!empty($arrayNullStock)) {
        throw new HttpException("Có " . count($arrayNullStock) . " sản phẩm chưa chọn kho !");
      }

      foreach ($combos as $combo) {
        $product_current_id = $product->id;
        $product_parent_id = $combo['product_id'];
        unset($combo['product_id']);
        if ($combo['type'] === 'single') {
          $stockProductSingle =  $this->getStockProduct($product_parent_id, $combo['stock_id'], false);
          $saveCombo = ProductParent::updateOrCreate([
            'product_id' => $product_current_id,
            'product_parent_id' => $product_parent_id,
            'type' => 'single'
          ], array_merge([
            'product_id' => $product_current_id,
            'product_parent_id' => $product_parent_id,
            'stock_product_id' => $stockProductSingle->id ?? null,
            'type' => 'single'
          ], $combo));
          $productParentIds[] = $saveCombo->id;
        }

        if ($combo['type'] === 'variable') {
          foreach ($combo['variants'] as $variant) {
            $stockProductVariant =  $this->getStockProduct($product_parent_id, $variant['stock_id'], $variant['attribute_first_id']);
            if (isset($variant['product_id'])) {
              unset($variant['product_id']);
            }
            $saveVariantCombo = ProductParent::updateOrCreate(
              [
                'product_id' => $product_current_id,
                'product_parent_id' => $product_parent_id,
                'attribute_first_id' => $variant['attribute_first_id'],
                'type' => 'variable'
              ],
              array_merge([
                'product_id' => $product_current_id,
                'product_parent_id' => $product_parent_id,
                'stock_product_id' => $stockProductVariant->id ?? null,
                'attribute_first_id' => $variant['attribute_first_id'],
                'type' => 'variable'
              ], $variant)
            );

            $productParentIds[] = $saveVariantCombo->id;
          }
        }
      }
      ProductParent::where('product_id', $product->id)->whereNotIn('id', $productParentIds)->delete();
    }
    if (empty($combos)) {
      ProductParent::where('product_id', $product->id)->delete();
    }
  }

  private function getTotalItemCombo($product, $combos = [])
  {
    $total = 0;
    if (!empty($combos) && $product) {
      $arrayNullStock = array_filter($combos, function ($item) {
        return !isset($item['stock_id']) && $item['type'] !== 'variable';
      });

      if (!empty($arrayNullStock)) {
        throw new HttpException("Có " . count($arrayNullStock) . " sản phẩm chưa chọn kho !");
      }

      foreach ($combos as $combo) {
        if ($combo['type'] === 'single') {
          $total += $combo['price_sale_combo'];
        }

        if ($combo['type'] === 'variable') {
          foreach ($combo['variants'] as $variant) {
            $total += $variant['price_sale_combo'];
          }
        }
      }
    }
    return  $total;
  }

  private function getStockProduct($product_id, $stock_id, $variant_id)
  {
    $query = StockProduct::where('stock_id', $stock_id)->where('is_main_stock', false)
      ->where('product_id', $product_id);
    if ($variant_id !== false) {
      $query->where('attribute_first_id', $variant_id);
    }
    $data = $query->first();
    return $data;
  }

  public function createManyOfRow($data)
  {
    try {
      $result = $this->CreateManyRow($data);

      return $result;
    } catch (Exception $e) {
      $this->error = $e->getMessage();
      return false;
    }
  }

  public function updatePrice($request)
  {
    $business_id = request()->header('business-id');
    $product = Product::where('business_id', $business_id)
      ->where('id', $request->id)
      ->first();
    if (empty($product)) {
      throw new HttpException("sản phẩm không tồn tại", 500);
    }
    $status = "pending";
    if (!empty($statusStock)) {
      $status = $statusStock;
    }
    $priceData = $request->priceData;
    if (!empty($priceData) && count($priceData) > 0) {
      foreach ($priceData as $item) {
        $dataProductStock = [
          'quantity' => !empty($item["quantity"]) ? $item["quantity"] : 0,
          'purchase_price' => !empty($item["purchase_price"]) ? $item["purchase_price"] : 0,
          'unit_price' => !empty($item["unit_price"]) ? $item["unit_price"] : 0,
          //                        'sale_price' => !empty($item["sale_price"]) ? $item["sale_price"] : 0,
          //                        'sale_price_max' => !empty($item["sale_price_max"]) ? $item["sale_price_max"] : 0,
          'status' => $status,
          'updated_at' => now(),
        ];
        $productStockExit = StockProduct::where("product_id", $product->id)
          ->where("stock_id", $item["id"])
          ->first();
        $logName = "";
        if ($productStockExit) {
          if ($productStockExit->quantity != $item["quantity"]) {
            $logName .= ";Thay đổi số lượng thành  " . number_format($item["quantity"]);
          }

          if ($productStockExit->unit_price != $item["unit_price"]) {
            $logName .= ";Thay đổi giá bán thành  " . number_format($item["unit_price"]);
          }

          if ($productStockExit->purchase_price != $item["purchase_price"]) {
            $logName .= ";Thay đổi giá mua thành  " . number_format($item["purchase_price"]);
          }
        }

        $productStock = StockProduct::where("product_id", $product->id)
          ->where("stock_id", $item["id"])
          ->update($dataProductStock);

        if (!$productStock) {
          $message = "Không thể lưu dữ liệu";
          throw new DatabaseException($message, 503);
        }
        return $product;
      }
    }
  }

  public function update($request)
  {
    try {
      return DB::transaction(function () use ($request) {
        request()->merge(['all_variant' => 1]);
        $business_id = request()->header('business-id');
        $user_id = Auth::guard('api')->user()->id;
        $statusStock = $request->status;
        $notForSell = $request->not_for_selling;

        $product_details = $request->only([
          'contact_id',
          'name',
          'brand_id',
          'unit_id',
          'category_id',
          'tax',
          'type',
          'barcode_type',
          'barcode',
          'sku',
          'alert_quantity',
          'tax_type',
          'weight',
          'product_custom_field1',
          'product_custom_field2',
          'product_custom_field3',
          'product_custom_field4',
          'product_description',
          'sub_unit_ids',
          'thumbnail',
          'images',
          'remove_images',
          'not_for_selling',
          'enable_sr_no',
          'warranty_id',
          'variants',
          'combo',
          'have_variant'
        ]);
        $type = $request->type;
        if ($request->have_variant == 0 &&  $type !== 'combo') {
          $type = 'single';
        }
        $product_details['type'] = $type;
        $product = Product::where('business_id', $business_id)
          ->where('id', $request->id)
          ->first();
        $productWithSku = Product::where('business_id', $business_id)
          ->where('sku', $request->sku)
          ->first();
        if ($product && $productWithSku) {
          if ($product->sku == $productWithSku->sku && $product->sku != $request->sku) {
            throw new HttpException('Sku đã tồn tại', 400);
          }
        }
        if ($product) {
          if (isset($product_details['name'])) {
            $product->name = $product_details['name'];
          }

          if (isset($product_details['brand_id'])) {
            $product->brand_id = $product_details['brand_id'];
          }

          if (isset($product_details['unit_id'])) {
            $product->unit_id = $product_details['unit_id'];
          }

          if (isset($product_details['contact_id'])) {
            $product->contact_id = $product_details['contact_id'];
          }

          if (isset($product_details['contact_id'])) {
            if ($product_details['contact_id'] == null) {
              $product->contact_id = "";
            }
          }

          if (isset($product_details['category_id'])) {
            $product->category_id = $product_details['category_id'];
          }

          if (isset($product_details['tax'])) {
            $product->tax = $product_details['tax'];
          }

          if (isset($product_details['barcode_type'])) {
            $product->barcode_type = $product_details['barcode_type'];
          }

          if (isset($product_details['barcode'])) {
            $product->barcode = $product_details['barcode'];
          }

          if (isset($product_details['sku'])) {
            $productSku = Product::where('business_id', $business_id)
              ->where('sku', $request->sku)
              ->where('id', '!=', $product->id)
              ->first();
            if ($productSku) {
              throw new HttpException('SKU đã tồn tại', 400);
            }
            $product->sku = $product_details['sku'];
          }

          if (isset($product_details['alert_quantity'])) {
            $product->alert_quantity = $product_details['alert_quantity'];
          }

          if (isset($product_details['tax_type'])) {
            $product->tax_type = $product_details['tax_type'];
          }

          if (isset($product_details['weight'])) {
            $product->weight = $product_details['weight'];
          }

          if (isset($product_details['product_custom_field1'])) {
            $product->product_custom_field1 = $product_details['product_custom_field1'];
          }

          if (isset($product_details['product_custom_field2'])) {
            $product->product_custom_field2 = $product_details['product_custom_field2'];
          }

          if (isset($product_details['product_custom_field3'])) {
            $product->product_custom_field3 = $product_details['product_custom_field3'];
          }

          if (isset($product_details['product_custom_field4'])) {
            $product->product_custom_field4 = $product_details['product_custom_field4'];
          }

          if (isset($product_details['product_description'])) {
            $product->product_description = $product_details['product_description'];
          }
          if (isset($product_details['warranty_id'])) {
            $product->warranty_id = $product_details['warranty_id'];
          }

          if (isset($product_details['sub_unit_ids'])) {
            $product->sub_unit_ids = $product_details['sub_unit_ids'];
          }

          if (isset($product_details['enable_sr_no'])) {
            $product->enable_sr_no = $product_details['enable_sr_no'] == true ? 1 : 0;
          }

          $thumbnail = $request->input('thumbnail');
          if (isset($thumbnail)) {
            $product->image = !empty($thumbnail) ? str_replace(tera_url("/"), "", $thumbnail) : "";
          }

          if (!empty($request->input('enable_stock')) && $request->input('enable_stock') == 1) {
            $product->enable_stock = 1;
          } else {
            $product->enable_stock = 0;
          }

          if (isset($request->not_for_selling)) {
            $product->not_for_selling = $notForSell;
          }

          if (!empty($request->input('sub_category_id'))) {
            $product->sub_category_id = $request->input('sub_category_id');
          } else {
            $product->sub_category_id = null;
          }

          // remove_images
          $removeImages = $request->input('remove_images');
          if (!empty($removeImages) && count($removeImages) > 0) {
            foreach ($removeImages as $item) {
              ProductImage::findOrFail($item)->delete();
            }
          }
          $status = "pending";
          if (!empty($statusStock)) {
            $status = $statusStock;
          }
          $logName = "Cập nhật sản phẩm " . $product->name;
          $idsStockValid = [];
          $priceData = $request->priceData;
          if (!empty($priceData) && count($priceData) > 0) {
            foreach ($priceData as $item) {
              if ($type === 'combo') {
                $unit_price = !empty($item["unit_price"]) ? $item["unit_price"] : 0;
                $combo_unit_price =   $unit_price + $this->getTotalItemCombo($product, request()->combo ?? []);
              } else {
                $combo_unit_price = !empty($item["unit_price"]) ? $item["unit_price"] : 0;
              }
              $dataProductStock = [
                'combo_unit_price' => $combo_unit_price,
                'quantity' => !empty($item["quantity"]) ? $item["quantity"] : 0,
                'purchase_price' => !empty($item["purchase_price"]) ? $item["purchase_price"] : 0,
                'unit_price' =>  !empty($item["unit_price"]) ? $item["unit_price"] : 0,
                'sale_price' => !empty($item["sale_price"]) ? $item["sale_price"] : 0,
                'sale_price_max' => !empty($item["sale_price_max"]) ? $item["sale_price_max"] : 0,
                'status' => $status,
                'stock_id' => @$item["stock_id"],
                'product_id' => $product->id,
                'is_main_stock' => true,
                'updated_at' => now(),
              ];
              $productStockExit = StockProduct::where("product_id", $product->id)
                ->where("stock_id", $item["id"])
                ->where("is_main_stock", true)
                ->first();
              if ($productStockExit) {
                if ($productStockExit->quantity != $item["quantity"]) {
                  $logName .= ";Thay đổi số lượng thành  " . number_format($item["quantity"]);
                }

                if ($productStockExit->unit_price != $item["unit_price"]) {
                  $logName .= ";Thay đổi giá bán thành  " . number_format($item["unit_price"]);
                }

                if ($productStockExit->purchase_price != $item["purchase_price"]) {
                  $logName .= ";Thay đổi giá mua thành  " . number_format($item["purchase_price"]);
                }

                $productStockExit->update($dataProductStock);
                $idsStockValid[] = $productStockExit->id;
              } else {
                $createNewStock =  StockProduct::create($dataProductStock);
                if ($createNewStock) {
                  $idsStockValid[] = $createNewStock->id;
                }
              }

              $message = "[$product->sku]-[" . $request->input("name") . "]";

              Activity::activityLog($message, $product->id, "crm_product", "edited_price", $user_id);
            }
          }

          $variations = $request->variations;
          if (!empty($variations) && count($variations) > 0) {
            $dataVariations = [];

            foreach ($variations as $item) {
              if (isset($item["is_new"]) && $item["is_new"] === true) {
                $dataProductVariations = [
                  'name' => !empty($item["name"]) ? $item["name"] : 0,
                  'value' => !empty($item["value"]) ? $item["value"] : 0,
                  'product_id' => $product->id,
                  'created_at' => now(),
                ];

                array_push($dataVariations, $dataProductVariations);
              }


              if (isset($item["is_update"]) && $item["is_update"] === true) {
                $dataProductVariations = [
                  'name' => !empty($item["name"]) ? $item["name"] : 0,
                  'value' => !empty($item["value"]) ? $item["value"] : 0,
                  'updated_at' => now(),
                ];

                $variationResult = ProductVariation::where("product_id", $product->id)
                  ->where("id", $item["id"])
                  ->update($dataProductVariations);

                if (!$variationResult) {
                  $message = "Không thể lưu dữ liệu";
                  throw new DatabaseException($message, 503);
                }
              }

              if (isset($item["is_remove"]) && $item["is_remove"] === true) {
                $variationResult = ProductVariation::where("product_id", $product->id)
                  ->where("id", $item["id"])
                  ->delete();

                if (!$variationResult) {
                  $message = "Không thể lưu dữ liệu";
                  throw new DatabaseException($message, 503);
                }
              }
            }


            if (count($dataVariations) > 0) {
              $variationResult = ProductVariation::insert($dataVariations);
              if (!$variationResult) {
                $message = "Không thể lưu dữ liệu";
                throw new DatabaseException($message, 503);
              }
            }
          }

          $listVariation = $request->listVariation;

          if (!empty($listVariation) && count($listVariation) > 0) {
            $dataListVariations = [];

            foreach ($listVariation as $item) {
              $urlImage = !empty($item["images"]) ? str_replace(tera_url("/"), "", $item["images"]) : "";

              if (isset($item["is_new"]) && $item["is_new"] === true) {
                $dataVariations = [
                  'name' => !empty($item["name"]) ? $item["name"] : "",
                  'description' => !empty($item["description"]) ? $item["description"] : "",
                  'allowSerial' => !empty($item["allowSerial"]) ? $item["allowSerial"] : 0,
                  'barcode' => !empty($item["barcode"]) ? $item["barcode"] : "",
                  'sub_sku' => !empty($item["sku"]) ? $item["sku"] : "",
                  'image' => !empty($item["images"]) ? $urlImage : "",
                  'status' => !empty($item["status"]) ? $item["status"] : "active",
                  'default_sell_price' => !empty($item["unit_price"]) ? $item["unit_price"] : 0,
                  'sell_price_inc_tax' => !empty($item["sale_price"]) ? $item["sale_price"] : 0,
                  'product_id' => $product->id,
                  'created_at' => now(),
                ];

                array_push($dataListVariations, $dataVariations);
              }

              if (isset($item["is_update"]) && $item["is_update"] === true) {
                $dataVariations = [
                  'name' => !empty($item["name"]) ? $item["name"] : "",
                  'description' => !empty($item["description"]) ? $item["description"] : "",
                  'allowSerial' => !empty($item["allowSerial"]) ? $item["allowSerial"] : 0,
                  'barcode' => !empty($item["barcode"]) ? $item["barcode"] : "",
                  'sub_sku' => !empty($item["sku"]) ? $item["sku"] : "",
                  'image' => !empty($item["images"]) ? $urlImage : "",
                  'status' => !empty($item["status"]) ? $item["status"] : "active",
                  'default_sell_price' => !empty($item["unit_price"]) ? $item["unit_price"] : 0,
                  'sell_price_inc_tax' => !empty($item["sale_price"]) ? $item["sale_price"] : 0,
                  'updated_at' => now(),
                ];
              }
            }


            if (count($dataListVariations) > 0) {
              $variationResult = Variation::insert($dataListVariations);
              if (!$variationResult) {
                $message = "Không thể lưu dữ liệu";
                throw new DatabaseException($message, 503);
              }
            }
          }


          // remove_images
          $requestImages = $request->input('images');
          if (!empty($requestImages) && count($requestImages) > 0) {
            $dataProductImage = [];
            foreach ($requestImages as $item) {
              $urlImage = !empty($item["url"]) ? str_replace(tera_url("/"), "", $item["url"]) : "";
              $dataProductImage[] = [
                'product_id' => $product->id,
                'image' => str_replace("/thumb", "", $urlImage),
                'thumb' => $urlImage,
                'created_at' => now(),
              ];
            }

            $productImage = ProductImage::insert($dataProductImage);

            if (!$productImage) {
              $message = "Không thể lưu dữ liệu";
              throw new DatabaseException($message, 503);
            }
          }

          $stock_id = $request->header('stock-id');

          if (isset($request->update_status) && $stock_id) {
            $productStock = StockProduct::where("product_id", $product->id)
              ->where("stock_id", $stock_id)
              ->update([
                "status" => $statusStock
              ]);

            if (!$productStock) {
              $message = "Không thể lưu dữ liệu";
              throw new DatabaseException($message, 503);
            }
          }
          $product->save();
          $product->touch();
          //
          if ($type === 'variable') {
            $variants = $request->variants ?? [];
            $idsStockValid = array_merge($this->saveVariants($product, $variants, $status), $idsStockValid);
          }
          if ($type === 'combo') {
            $combo = $request->combo ?? [];
            $this->saveCombo($product, $combo);
          }

          $this->removeVariantsStockExcept($product->id, $idsStockValid);
          //
          $message = "[$product->sku]-[" . $request->input("name") . "]";

          Activity::activityLog($message, $product->id, "crm_product", "edited", $user_id);

          return $product;
        }
      });
    } catch (HttpException $e) {

      $message = $e->getMessage();

      throw new HttpException($message, 500);
    }
  }


  public function delete($id)
  {
    DB::beginTransaction();

    try {
      $business_id = request()->header('business-id');
      $user_id = Auth::guard('api')->user()->user_id;

      $can_be_deleted = true;
      $error_msg = '';

      //Check if any purchase or transfer exists
      $count = Transaction::leftJoin('transaction_sell_lines', 'transaction_sell_lines.transaction_id', '=', 'transactions.id')
        ->leftJoin('purchase_lines', 'purchase_lines.transaction_id', '=', 'transactions.id')
        ->where('transactions.business_id', $business_id)
        ->where(function ($query) use ($id) {
          $query->where('transaction_sell_lines.product_id', $id)
            ->orWhere('purchase_lines.product_id', $id);
        })
        ->count();
      if ($count > 0) {
        $can_be_deleted = false;
        $error_msg = __('Không thể xoá sản phẩm tồn tại trong đơn hàng');
      } else {
        //Check if any opening stock sold
        $count = PurchaseLine::join(
          'transactions as T',
          'purchase_lines.transaction_id',
          '=',
          'T.id'
        )
          ->where('T.type', 'opening_stock')
          ->where('T.business_id', $business_id)
          ->where('purchase_lines.product_id', $id)
          ->where('purchase_lines.quantity_sold', '>', 0)
          ->count();
        if ($count > 0) {
          $can_be_deleted = false;
          $error_msg = __('lang_v1.opening_stock_sold');
        } else {
          //Check if any stock is adjusted
          $count = PurchaseLine::join(
            'transactions as T',
            'purchase_lines.transaction_id',
            '=',
            'T.id'
          )
            ->where('T.business_id', $business_id)
            ->where('purchase_lines.product_id', $id)
            ->where('purchase_lines.quantity_adjusted', '>', 0)
            ->count();
          if ($count > 0) {
            $can_be_deleted = false;
            $error_msg = __('lang_v1.stock_adjusted');
          }
        }
      }

      $product = Product::where('id', $id)
        ->where('business_id', $business_id)
        ->with('variations')
        ->first();
      $nameProduct = $product->name;
      $skuProduct = $product->sku;
      $idProduct = $product->id;
      if ($can_be_deleted) {
        if (!empty($product)) {
          VariationLocationDetails::where('product_id', $id)
            ->delete();
          $product->delete();
          $message = "[$skuProduct]-[" . $nameProduct . "]";

          Activity::activityLog($message, $idProduct, "crm_product", "deleted", $user_id);
          // Activity::history($message, "product", $dataLog);

          DB::commit();

          return $product;
        }
      }

      throw new HttpException($error_msg, 500);
    } catch (\Exception $e) {
      $message = $e->getMessage();

      throw new HttpException($message, 500);
    }
  }

  public function updateStatus($request)
  {
    try {
      $ids = $request->ids;
      if (!$ids || count($ids) === 0) {
        $res = [
          'status' => 'danger',
          'msg' => "Không tìm sản phẩm"
        ];

        throw new HttpException($res, 404);
      }

      $business_id = request()->header('business-id');
      $user_id = Auth::guard('api')->user()->id;
      $stock_id = $request->header('stock-id', null);

      $status = $request->status;

      if (empty($status)) {
        $res = [
          'status' => 'danger',
          'msg' => "Không có dữ liệu được cập nhật"
        ];

        throw new HttpException($res, 404);
      }

      $dataUpdate = [
        'status' => $status
      ];
      $transaction = StockProduct::whereIn('product_id', $ids)
        ->update($dataUpdate);
      foreach ($request->ids as $key => $value) {
        $product = Product::where('id', $value)->first();
        if ($product) {
          $message = "[$product->sku]-[" . $product->name . "]";

          Activity::activityLog($message, $product->id, "crm_product", $status, $user_id);
        }
      }
      DB::commit();

      return $transaction;
    } catch (\Exception $e) {

      DB::rollBack();
      $message = $e->getMessage();

      throw new HttpException($message, 500);
    }
  }

  public function history($request)
  {
    try {
      $business_id = request()->header('business-id');

      if (empty($request->product_id)) {
        $message = "Vui lòng chọn 1 sản phẩm";
        throw new HttpException($message, 400);
      }

      $product_id = $request->product_id;
      $type = $request->type;

      if ($type === "customer") {
        $data['data'] = $this->getListCustomerByProduct($request, $business_id, $product_id);
        $summary = $this->getSummaryHistory($request, $business_id, $product_id);
        $data['summary'] = $summary;
        return $data;
      }

      $data['data'] = $this->getListCustomerByProduct($request, $business_id, $product_id);
      $summary = $this->getSummaryHistory($request, $business_id, $product_id);
      $data['summary'] = $summary;
      return $data;
    } catch (\Exception $e) {
      $message = $e->getMessage();

      throw new HttpException($message, 500);
    }
  }

  public function importData($request)
  {
    try {
      $business_id = request()->header('business-id');
      $user_id = Auth::guard('api')->user()->id;

      //Set maximum php execution time
      ini_set('max_execution_time', 0);
      ini_set('memory_limit', -1);

      if ($request->hasFile('file')) {
        $file = $request->file('file');

        $parsed_array = Excel::toArray([], $file);

        //Remove header row
        $imported_data = array_splice($parsed_array[0], 1);

        $formated_data = [];
        $prices_data = [];

        $is_valid = true;
        $is_replace = false;
        $columnReplace = [];
        $error_msg = '';

        $total_rows = count($imported_data);

        if (isset($request->column_select) && $request->column_select) {
          $columnReplace = explode(',', $request->column_select);
          if (count($columnReplace) > 0) {
            $is_replace = true;
          }
        }

        foreach ($imported_data as $key => $value) {
          //Check if any column is missing
          if (count($value) < 8) {
            $is_valid = false;
            $error_msg = "Thiếu cột trong quá trình tải lên dữ liệu. vui lòng tuần thủ dữ template";
            break;
          }

          $row_no = $key + 1;
          $product_array = [];
          $product_array['business_id'] = $business_id;
          $product_array['created_by'] = $user_id;
          $product_array['type'] = "single";
          $product_array['barcode_type'] = 'C128';

          //Add product name
          $product_name = trim($value[1]);
          if (!empty($product_name)) {
            $product_array['name'] = $product_name;
          } else {
            $is_valid = false;
            $error_msg = "Tên sản phẩm không được tìm thấy ở hàng thứ. $row_no";
            break;
          }

          //Add unit
          $unit_name = trim($value[3]);
          if (!empty($unit_name)) {
            $unit = Unit::where('business_id', $business_id)
              ->where(function ($query) use ($unit_name) {
                $query->where('short_name', $unit_name)
                  ->orWhere('actual_name', $unit_name);
              })->first();
            if (!empty($unit)) {
              $product_array['unit_id'] = $unit->id;
            } else {
              $is_valid = false;
              $error_msg = "Đơn vị không được tìm thấy ở hàng thứ . $row_no";
              break;
            }
          } else {
            $is_valid = false;
            $error_msg = "Đơn vị không được tìm thấy ở hàng thứ . $row_no";
            break;
          }

          //Add category
          $category_name = trim($value[4]);
          if (!empty($category_name)) {
            $category = Category::where('business_id', $business_id)
              ->where(function ($query) use ($category_name) {
                $query->where('name', $category_name);
              })->first();
            if (!empty($category)) {
              $product_array['category_id'] = $category->id;
            } else {
              $is_valid = false;
              $error_msg = "Danh mục không được tìm thấy ở hàng thứ . $row_no";
              break;
            }
          }

          //Add brand
          $brand_name = trim($value[5]);
          if (!empty($brand_name)) {
            $brand = Brands::where('business_id', $business_id)
              ->where(function ($query) use ($brand_name) {
                $query->where('name', $brand_name);
              })->first();
            if (!empty($brand)) {
              $product_array['brand_id'] = $brand->id;
            } else {
              $is_valid = false;
              $error_msg = "Thương hiệu không được tìm thấy ở hàng thứ . $row_no";
              break;
            }
          }

          // supplier
          $category_name = trim($value[2]);
          if (!empty($category_name)) {
            $supplier = Contact::where('type', "supplier")
              ->where(function ($query) use ($category_name) {
                $query->where('supplier_business_name', $category_name)
                  ->orWhere('first_name', $category_name);
              })->first();
            if (!empty($supplier)) {
              $product_array['contact_id'] = $supplier->id;
            } else {
              $is_valid = false;
              $error_msg = "Nhà cung cấp không được tìm thấy ở hàng thứ. $row_no";
              break;
            }
          } else {
            $is_valid = false;
            $error_msg = "Nhà cung cấp không được tìm thấy ở hàng thứ. $row_no";
            break;
          }

          //Add SKU
          $sku = trim($value[0]);
          if (!empty($sku)) {
            $product_array['sku'] = $sku;
            //Check if product with same SKU already exist
            $is_exist = Product::where('sku', $product_array['sku'])
              ->where('business_id', $business_id)
              ->exists();
            if ($is_exist && $is_replace === false) {
              $is_valid = false;
              $error_msg = "$sku SKU đã tồn tại ở dòng thứ. $row_no";
              break;
            }
          } else {
            $product_array['sku'] = ' ';
          }

          // sell price
          $sell_price = trim($value[8]);
          $purchase_price = trim($value[7]);
          $quantity = trim($value[9]);
          $sale_price = trim($value[10]);
          $sale_price_max = trim($value[11]);

          if (!$sell_price) $sell_price = 0;
          if (!$purchase_price) $purchase_price = 0;
          if (!$quantity) $quantity = 0;
          if (!$sale_price) $sale_price = 0;
          if (!$sale_price_max) $sale_price_max = 0;

          $formated_data[] = $product_array;
          $prices_data[] = [
            "sale_price" => $sale_price,
            "sale_price_max" => $sale_price_max,
            "sell_price" => $sell_price,
            "purchase_price" => $purchase_price,
            "quantity" => $quantity
          ];
        }

        if (!$is_valid) {
          throw new HttpException($error_msg);
        }

        if (!empty($formated_data)) {
          foreach ($formated_data as $index => $product_data) {
            $data_stock = [
              "stock_id" => 6,
              "purchase_price" => $prices_data[$index]['purchase_price'],
              "unit_price" => $prices_data[$index]['sell_price'],
              "sale_price" => $prices_data[$index]['sale_price'],
              "sale_price_max" => $prices_data[$index]['sale_price_max'],
              "quantity" => $prices_data[$index]['quantity'],
              "status" => "approve",
            ];

            //Create new product
            $prodItem = Product::where('sku', $product_data['sku'])
              ->where('business_id', $business_id)
              ->first();

            if ($prodItem && $is_replace === true) {
              $dataProductUpdate = [];
              $dataPriceUpdate = [];

              foreach ($columnReplace as $value) {
                if (isset($product_data[$value])) {
                  $dataProductUpdate[$value] = $product_data[$value];
                }

                if (isset($data_stock[$value])) {
                  $dataPriceUpdate[$value] = $data_stock[$value];
                }

                Product::where('sku', $product_data['sku'])
                  ->update($dataProductUpdate);

                StockProduct::where('stock_id', 6)
                  ->where("product_id", $prodItem->id)
                  ->update($dataPriceUpdate);
              }
            } else {
              $product = Product::create($product_data);

              $data_stock["product_id"] = $product->id;

              StockProduct::create($data_stock);

              if ($product->sku == ' ') {
                $sku = $this->productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
              }
            }
          }
        }
      }

      return true;
    } catch (\Exception $e) {
      $message = $e->getMessage();

      throw new HttpException($e, 500);
    }
  }

  private function getListAllProduct($request, $business_id, $contact_id = null)
  {
    $stock_id = request()->header('stock-id', null);
    $query = Product::with([
      'variants.stock',
      'stock_products.stock:id,stock_name,is_default',
      'variants.variantFirst.variant'
    ])->where('products.business_id', $business_id)
      ->leftJoin("stock_products", function ($join) use ($stock_id) {
        $join->on('stock_products.product_id', '=', 'products.id');
        $join->on('stock_products.stock_id', '=', DB::raw($stock_id));
      })
      ->leftJoin(
        'units',
        'units.id',
        '=',
        'products.unit_id'
      )
      ->leftJoin(
        'categories',
        'categories.id',
        '=',
        'products.category_id'
      )
      ->leftJoin(
        'brands',
        'brands.id',
        '=',
        'products.brand_id'
      )
      ->leftJoin(
        'crm_customers',
        'crm_customers.id',
        '=',
        'products.contact_id'
      )
      ->with([
        'product_images',
        'stock_products',
        "brand",
        "contact",
        "unit",
        "category",
        "warranty"
      ])
      ->where('products.type', '!=', 'modifier');

    if (!empty($contact_id)) {
      $query->where("products.contact_id", $contact_id);
    }

    if (!empty(request()->except_type) && request()->except_type) {
      $query->where("products.type", "!=", request()->except_type);
    }

    $products = $query->select(
      'products.id',
      'products.name as product',
      'products.type',
      'products.sku',
      'products.barcode',
      'products.image',
      'products.not_for_selling',
      'products.is_inactive',
      'products.unit_id',
      'products.brand_id',
      'products.category_id',
      'products.contact_id',
      'products.warranty_id',
      'products.created_at'
    )->groupBy('products.id');

    $status = request()->get('status', null);
    if (!empty($status) && $status !== "all") {
      $products->where('stock_products.status', '=', $status);
    }

    $category_id = request()->get('category_id', null);
    if (!empty($category_id)) {
      $products->where('products.category_id', $category_id);
    }

    $brand_id = request()->get('brand_id', null);
    if (!empty($brand_id)) {
      $products->where('products.brand_id', $brand_id);
    }

    $unit_id = request()->get('unit_id', null);
    if (!empty($unit_id)) {
      $products->where('products.unit_id', $unit_id);
    }

    $category = request()->get('category', null);
    if (!empty($category)) {
      $products->where("categories.name", "LIKE", "%$category%");
    }

    $brand = request()->get('brand', null);
    if (!empty($brand)) {
      $products->where("brands.name", "LIKE", "%$brand%");
    }

    $unit = request()->get('unit', null);
    if (!empty($unit)) {
      $products->where("units.actual_name", "LIKE", "%$unit%");
    }

    $tax_id = request()->get('tax_id', null);
    if (!empty($tax_id)) {
      $products->where('products.tax', $tax_id);
    }

    $active_state = request()->get('active_state', null);
    if ($active_state == 'active') {
      $products->Active();
    }
    if ($active_state == 'inactive') {
      $products->Inactive();
    }

    $contact_id = request()->get('contact_id', null);
    if (!empty($contact_id) && (empty($request->showAll) || $request->showAll != "true")) {
      $products->where('products.contact_id', $contact_id);
    }

    if (isset($request->contact_name) && $request->contact_name) {
      $products->whereHas('contact', function ($subQuery) use ($request) {
        $subQuery->where('business_name', 'LIKE', '%' . $request->contact_name . '%');
      });
    }

    if (isset($request->barcode) && $request->barcode) {
      $products->where("products.barcode", "LIKE", "%$request->barcode%");
    }

    if (isset($request->id) && $request->id) {
      $products->where("products.id", "LIKE", "%$request->id%");
    }

    if (isset($request->product_name) && $request->product_name) {
      $products->where("products.name", "LIKE", "%$request->product_name%");
    }

    if (isset($request->sku) && $request->sku) {
      $products->where("products.sku", "LIKE", "%$request->sku%");
    }

    if (isset($request->supplier_id) && $request['showAll'] != 'true') {
      $supplier_id = $request->supplier_id;
      $products->where("crm_customers.id", $supplier_id);
    }

    //if (isset($request->keyword) && $request->keyword) {
    //    $query = "(products.name LIKE '%$request->keyword%' OR products.sku LIKE '%$request->keyword%'  OR products.barcode LIKE '%$request->keyword%')";
    //    $products->whereRaw($query);
    //}
    $keyword = request()->keyword;

    if (isset($keyword) && $keyword) {
      $products->where(function ($query) use ($keyword) {
        $query->where('products.sku', 'LIKE', '%' . $keyword . '%')
          ->orWhere('products.name', 'LIKE', "%$keyword%");
      })->orWhereHas('variants', function ($query) use ($keyword) {
        $query->where('stock_products.sku', 'LIKE', '%' . $keyword . '%');
      });
    }

    if (!empty(request()->start_date)) {
      $start = DateTime::createFromFormat('d/m/Y', request()->start_date)->format('Y-m-d');
      $products->whereDate('products.created_at', '>=', $start);
    }


    if (!empty(request()->end_date)) {
      $end = DateTime::createFromFormat('d/m/Y', request()->end_date)->format('Y-m-d');
      $products->whereDate('products.created_at', '<=', $end);
    }

    $is_image = request()->get('is_image');
    if (!empty($is_image)) {
      $products->where('products.image', "");
    }

    $sort_field = "products.created_at";
    $sort_des = "desc";

    if (isset($request->order_field) && $request->order_field) {
      $sort_field = $request->order_field;
    }

    if (isset($request->order_by) && $request->order_by) {
      $sort_des = $request->order_by;
    }

    $include_id = request()->get('include_id', null);
    if (!empty($include_id)) {
      $products->orderByRaw("id = $include_id desc");
    }
    $products->orderBy("products.created_at", "desc");
    $products->orderBy($sort_field, $sort_des);
    $data = $products->paginate($request->limit);
    $data->getCollection()->transform(function ($item, $key) use ($data) {
      if (isset(request()->is_append_variant) && request()->is_append_variant == 1) {
        $this->appendsVariant($item);
      }
      $item->record_number = ($data->currentPage() - 1) * $data->perPage() + $key + 1;
      return $item;
    });
    return $data;
  }

  private function getListCustomerByProduct($request, $business_id, $product_id = null)
  {
    $data = [];
    if ($request->status == 'sell_history') {
      $data = $this->getHistorySell($request, $business_id, $product_id);
    }

    if ($request->status == 'purchase_history') {
      $data = $this->getPurchaseHistory($request, $business_id, $product_id);
    }
    return $data;
  }

  public function getSummaryHistory($request, $business_id, $product_id = null)
  {
    $totalTransactionSellLine = TransactionSellLine::where('product_id', $product_id)
      ->join(
        'transactions',
        'transactions.id',
        '=',
        'transaction_sell_lines.transaction_id'
      )
      ->where('transactions.type', 'sell')
      ->count();
    $totalPurchaseLine = PurchaseLine::where('product_id', $product_id)
      ->join(
        'transactions',
        'transactions.id',
        '=',
        'purchase_lines.transaction_id'
      )
      ->where('transactions.type', 'purchase')
      ->count();
    return [
      [
        'status' => 'purchase_history',
        'total_count' => $totalPurchaseLine,
      ],
      [
        'status' => 'sell_history',
        'total_count' => $totalTransactionSellLine,
      ]
    ];
  }

  private function getHistorySell($request, $business_id, $product_id = null)
  {
    $stock_id = $request->header('stock-id', null);
    $query = TransactionSellLine::leftJoin(
      'transactions',
      'transactions.id',
      '=',
      'transaction_sell_lines.transaction_id'
    )
      ->leftJoin(
        'products',
        'products.id',
        '=',
        'transaction_sell_lines.product_id'
      )
      ->leftJoin(
        'crm_customers',
        'crm_customers.id',
        '=',
        'transactions.contact_id'
      )
      ->leftJoin("stock_products", function ($join) use ($stock_id) {
        $join->on('stock_products.product_id', '=', 'products.id');
        $join->on('stock_products.stock_id', '=', DB::raw($stock_id));
      })
      ->with([
        'transaction',
        'product',
        'product.stock_products'
      ])
      ->where('transactions.business_id', $business_id)
      ->where('transactions.type', 'sell');

    if (!empty($product_id)) {
      $query->where("transaction_sell_lines.product_id", $product_id);
    }
    $products = $query->select(
      'transaction_sell_lines.*',
      'crm_customers.business_name as contact_name'
    );

    if (isset($request->contact_name) && $request->contact_name) {
      $query = "(crm_customers.business_name LIKE '%$request->contact_name%' OR crm_customers.business_name LIKE '%$request->contact_name%')";
      $products->whereRaw($query);
    }

    if (isset($request->keyword) && $request->keyword) {
      $query = "(crm_customers.business_name LIKE '%$request->keyword%' OR crm_customers.business_name LIKE '%$request->keyword%')";
      $products->whereRaw($query);
    }

    if (!empty(request()->start_date)) {
      $start = DateTime::createFromFormat('d/m/Y', request()->start_date)->format('Y-m-d');
      $products->whereDate('products.created_at', '>=', $start);
    }

    if (!empty(request()->end_date)) {
      $end = DateTime::createFromFormat('d/m/Y', request()->end_date)->format('Y-m-d');
      $products->whereDate('products.created_at', '<=', $end);
    }

    $sort_field = "products.created_at";
    $sort_des = "desc";

    if (isset($request->order_field) && $request->order_field) {
      $sort_field = $request->order_field;
    }

    if (isset($request->order_by) && $request->order_by) {
      $sort_des = $request->order_by;
    }

    $products->orderBy("transaction_sell_lines.updated_at", "desc");
    $products->orderBy($sort_field, $sort_des);
    return $products->paginate($request->limit);
  }

  private function getPurchaseHistory($request, $business_id, $product_id)
  {
    $stock_id = $request->header('stock-id ', null);
    $query = PurchaseLine::leftJoin(
      'transactions',
      'transactions.id',
      '=',
      'purchase_lines.transaction_id'
    )
      ->leftJoin(
        'products',
        'products.id',
        '=',
        'purchase_lines.product_id'
      )
      ->leftJoin(
        'crm_customers',
        'crm_customers.id',
        '=',
        'products.contact_id'
      )
      //                        ->leftJoin("stock_products", function ($join) use ($stock_id) {
      //                            $join->on('stock_products.product_id', '=', 'products.id');
      //                            $join->on('stock_products.stock_id', '=', DB::raw($stock_id));
      //                        })
      ->with([
        'transaction',
        'product',
        'product.stock_products'
      ])
      ->where('transactions.business_id', $business_id)
      ->where('transactions.type', 'purchase');

    if (!empty($product_id)) {
      $query->where("purchase_lines.product_id", $product_id);
    }

    $products = $query->select(
      'purchase_lines.*',
      'crm_customers.business_name as contact_name'
    );
    if (isset($request->contact_name) && $request->contact_name) {
      $query = "(crm_customers.business_name LIKE '%$request->contact_name%' OR crm_customers.business_name LIKE '%$request->contact_name%')";
      $products->whereRaw($query);
    }

    if (isset($request->keyword) && $request->keyword) {
      $query = "(crm_customers.business_name LIKE '%$request->keyword%' OR crm_customers.business_name LIKE '%$request->keyword%')";
      $products->whereRaw($query);
    }

    if (!empty(request()->start_date)) {
      $start = Carbon::createFromFormat('d/m/Y', request()->start_date);
      $products->where('products.created_at', '>=', $start);
    }

    if (!empty(request()->end_date)) {
      $end = Carbon::createFromFormat('d/m/Y', request()->end_date);
      $products->where('products.created_at', '<=', $end);
    }

    $sort_field = "products.created_at";
    $sort_des = "desc";

    if (isset($request->order_field) && $request->order_field) {
      $sort_field = $request->order_field;
    }

    if (isset($request->order_by) && $request->order_by) {
      $sort_des = $request->order_by;
    }

    $products->orderBy("purchase_lines.updated_at", "desc");
    $products->orderBy($sort_field, $sort_des);

    return $products->paginate($request->limit)
      ->toArray();
  }

  private function getListProductByContact($request, $business_id, $contact_id)
  {
    $latestTransactionLines = TransactionSellLine::leftJoin(
      'transactions',
      'transactions.id',
      '=',
      'transaction_sell_lines.transaction_id'
    )
      ->where('transactions.business_id', $business_id)
      ->where('transactions.type', 'sell')
      ->where('transactions.contact_id', $contact_id)
      ->select(
        'transaction_sell_lines.product_id',
        DB::raw('MAX(transaction_sell_lines.id) as id')
      )
      ->groupBy('transaction_sell_lines.product_id');
    $stock_id = $request->header('stock-id', null);

    $products = Product::with([
      'variants.stock',
      'stock_products.stock:id,stock_name,is_default',
      'variants.variantFirst.variant'
    ])->leftJoinSub($latestTransactionLines, 'tsl2', function ($join) {
      $join->on('products.id', '=', 'tsl2.product_id');
    })
      ->leftJoin(
        'transaction_sell_lines as tsl',
        'tsl.id',
        '=',
        'tsl2.id'
      )
      ->leftJoin("stock_products", function ($join) use ($stock_id) {
        $join->on('stock_products.product_id', '=', 'products.id');
        $join->on('stock_products.stock_id', '=', DB::raw($stock_id));
      })
      ->leftJoin(
        'units',
        'units.id',
        '=',
        'products.unit_id'
      )
      ->leftJoin(
        'brands',
        'brands.id',
        '=',
        'products.brand_id'
      )
      ->leftJoin(
        'categories',
        'categories.id',
        '=',
        'products.category_id'
      )
      ->leftJoin(
        'contacts',
        'contacts.id',
        '=',
        'products.contact_id'
      )
      ->where('tsl.id', '<>', null)
      ->with([
        'product_images',
        'stock_products',
        "brand",
        "contact",
        "unit",
        "category",
        "warranty"
      ])
      ->select(
        'products.id',
        'products.name as product',
        'products.type',
        'products.sku',
        'products.barcode',
        'products.image',
        'products.not_for_selling',
        'products.is_inactive',
        'products.unit_id',
        'products.brand_id',
        'products.category_id',
        'products.contact_id',
        'products.warranty_id',
        'tsl.unit_price',
        'tsl.purchase_price',
        'tsl.quantity'
      )
      ->groupBy("products.id");
    if (!empty(request()->except_type) && request()->except_type) {
      $products->where("products.type", "!=", request()->except_type);
    }
    $status = request()->get('status', null);
    if (!empty($status) && $status !== "all") {
      $products->whereHas('stock_products', function ($products) use ($status) {
        $products->where('stock_products.status', '=', $status);
      });
    }

    $category_id = request()->get('category_id', null);
    if (!empty($category_id)) {
      $products->where('products.category_id', $category_id);
    }

    $brand_id = request()->get('brand_id', null);
    if (!empty($brand_id)) {
      $products->where('products.brand_id', $brand_id);
    }

    $unit_id = request()->get('unit_id', null);
    if (!empty($unit_id)) {
      $products->where('products.unit_id', $unit_id);
    }

    $category = request()->get('category', null);
    if (!empty($category)) {
      $products->where("categories.name", "LIKE", "%$category%");
    }

    $brand = request()->get('brand', null);
    if (!empty($brand)) {
      $products->where("brands.name", "LIKE", "%$brand%");
    }

    $unit = request()->get('unit', null);
    if (!empty($unit)) {
      $products->where("units.actual_name", "LIKE", "%$unit%");
    }

    $tax_id = request()->get('tax_id', null);
    if (!empty($tax_id)) {
      $products->where('products.tax', $tax_id);
    }

    $active_state = request()->get('active_state', null);
    if ($active_state == 'active') {
      $products->Active();
    }
    if ($active_state == 'inactive') {
      $products->Inactive();
    }

    if (isset($request->barcode) && $request->barcode) {
      $products->where("products.barcode", "LIKE", "%$request->barcode%");
    }

    if (isset($request->id) && $request->id) {
      $products->where("products.id", "LIKE", "%$request->id%");
    }

    if (isset($request->product_name) && $request->product_name) {
      $products->where("products.name", "LIKE", "%$request->product_name%");
    }

    if (isset($request->sku) && $request->sku) {
      $products->where("products.sku", "LIKE", "%$request->sku%");
    }

    if (isset($request->keyword) && $request->keyword) {
      $keyword = $request->keyword;
      $query = "(products.name LIKE '%$keyword%' OR products.sku LIKE '%$keyword%'  OR products.barcode LIKE '%$keyword%')";
      $products->whereRaw($query)->orWhereHas('variants', function ($queryMain) use ($keyword) {
        $queryMain->where('stock_products.sku', 'LIKE', '%' . $keyword . '%');
      });
    }

    $is_image = request()->get('is_image');
    if (!empty($is_image)) {
      $products->where('products.image', "");
    }

    $sort_field = "products.created_at";
    $sort_des = "desc";

    if (isset($request->order_field) && $request->order_field) {
      $sort_field = $request->order_field;
    }

    if (isset($request->order_by) && $request->order_by) {
      $sort_des = $request->order_by;
    }

    if (isset($request->supplier_id)) {
      $supplier_id = $request->supplier_id;
      $products->where("crm_customers.id", $supplier_id);
    }

    $products->orderBy("products.updated_at", "desc");
    $products->orderBy($sort_field, $sort_des);

    $data = $products->paginate($request->limit);
    $data->getCollection()->transform(function ($item, $key) use ($data) {
      if (isset(request()->is_append_variant) && request()->is_append_variant == 1) {
        $this->appendsVariant($data);
      }
      $item->record_number = ($data->currentPage() - 1) * $data->perPage() + $key + 1;
      return $item;
    });
    return $data;
  }

  public function getByCondition($request)
  {
    $business_id = request()->header('business-id');
    if (isset($request['type']) && $request['type'] == 'sell-return' && isset($request['order_id'])) {
      return $this->getBySell($business_id, $request);
    }
    if (isset($request['order_id'])) {
      return $this->getByOrderId($request, $business_id);
    }
    if (isset($request['type']) && $request['type'] == 'sell-return') {
      return $this->getBySell($business_id, $request);
    }
    if (isset($request['type']) && $request['type'] == 'sell') {
      return $this->getBySell($business_id, $request);
    }
    if (isset($request['type']) && $request['type'] == 'price-quote') {
      return $this->getBySell($business_id, $request);
    }
    if (isset($request['type']) && $request['type'] == 'purchase') {
      return $this->getByPurchase($business_id, $request);
    }

    return $this->getAllProduct($request, $business_id);
  }

  private function getAllProduct($request, $business_id)
  {
    $query = Product::with([
      'variants.stock',
      'variants.variantFirst.variant',
      'combo',
      'contact'
    ])->select(
      'products.name',
      'products.name as product',
      'products.sku',
      'products.contact_id',
      'products.type',
      // 'stock_products.*',
      'products.id',
      DB::raw('JSON_OBJECT(
                    "id", units.id,
                    "actual_name", units.actual_name
                ) AS unit'),
      DB::raw('JSON_OBJECT(
                        "id", stock_products.id,
                        "product_id", stock_products.product_id,
                        "stock_id", stock_products.stock_id,
                        "unit_price", stock_products.unit_price,
                        "sku", stock_products.sku,
                        "status", stock_products.status,
                        "purchase_price", stock_products.purchase_price,
                        "unit_price_inc_tax", stock_products.unit_price,
                        "quantity", stock_products.quantity,
                        "image_url", stock_products.image_url,
                        "attribute_first_id", stock_products.attribute_first_id
                ) AS variant'),
      DB::raw('JSON_OBJECT(
                        "id", stocks.id,
                        "stock_name", stocks.stock_name,
                        "stock_type", stocks.stock_type,
                        "business_id", stocks.business_id,
                        "location_id", stocks.location_id,
                        "is_active", stocks.is_active,
                        "is_delete", stocks.is_delete,
                        "is_sync", stocks.is_sync,
                        "is_default", stocks.is_default
                ) AS stock'),
      DB::raw('JSON_OBJECT(
                        "id", tb_attr_1.id,
                        "variant_id", tb_attr_1.variant_id,
                        "title", tb_attr_1.title,
                        "order", tb_attr_1.order
                ) AS attribute_first')
    );
    $query->leftJoinStockProductsCondition()
      ->leftJoin('stocks', function ($join) {
        $join->on('stocks.id', '=', 'stock_products.stock_id');
      })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
      ->leftJoin('units', 'units.id', '=', 'products.unit_id')
      ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
      ->where('products.business_id', $business_id);
    if (!empty($request['keyword'])) {
      $keyword = $request['keyword'];
      $query->where(function ($query) use ($keyword) {
        $query->where('products.sku', 'LIKE', "%$keyword%")
          ->orWhere('products.name', 'LIKE', "%$keyword%");
      });
    }
    $data =  $query->paginate($request['limit']);
    return $this->transformerSellConditionWithVariantCombo($data);
  }

  private function getByOrderId($request, $business_id)
  {
    $transaction = Transaction::find($request['order_id']);

    if (empty($transaction)) {
      throw new HttpException('Không tìm thấy thông tin đơn hàng', 500);
    }
    if ($transaction->type == 'purchase' || $transaction->type == 'purchase_request') {
      $query = Product::select(
        'products.name',
        'products.name as product',
        'products.sku',
        'purchase_lines.purchase_price',
        'products.contact_id',
        'stock_products.*',
        'products.id',
        DB::raw('JSON_OBJECT(
                    "id", units.id,
                    "actual_name", units.actual_name
                ) AS unit'),
        DB::raw('JSON_OBJECT(
                    "id", crm_customers.id,
                    "business_name", crm_customers.business_name,
                    "code" , crm_customers.code,
                ) AS contact')
      );
      $query->leftJoin('purchase_lines', 'products.id', '=', 'purchase_lines.product_id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->leftJoin('stock_products', 'stock_products.product_id', '=', 'products.id')
        ->where('purchase_lines.transaction_id', $request['order_id'])
        ->where('stock_products.status', '=', 'approve')
        ->where('products.business_id', $business_id);
    } else {


      $query = Product::select(
        'products.id',
        'products.name',
        'products.sku',
        'products.name as product',
        'products.contact_id',
        'transaction_sell_lines.purchase_price',
        'transaction_sell_lines.unit_price',
        'stock_products.*',
        'products.id',
        DB::raw('JSON_OBJECT(
                    "id", units.id,
                    "actual_name", units.actual_name
                ) AS unit'),
        DB::raw('JSON_OBJECT(
                    "id", crm_customers.id,
                    "business_name", crm_customers.business_name,
                    "code" , crm_customers.code
                ) AS contact')
      );
      $query->leftJoin('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->leftJoin('stock_products', 'stock_products.product_id', '=', 'products.id')
        ->where('products.business_id', $business_id)
        ->where('stock_products.status', '=', 'approve')
        ->where('transaction_sell_lines.transaction_id', $request['order_id']);
    }
    if (!empty($request['keyword'])) {
      $keyword = $request['keyword'];
      $query->where(function ($query) use ($keyword) {
        $query->where('products.sku', 'LIKE', "%$keyword%")
          ->orWhere('products.name', 'LIKE', "%$keyword%");
      });
    }
    $query->orderBy('products.created_at', 'desc');
    return $query->paginate($request['limit'])->toArray();
  }

  private function getByPurchase($business_id, $request)
  {
    $query = Product::with([
      'variants.stock',
      'variants.variantFirst.variant',
      'combo',
      'contact'
    ])->select(
      'products.name',
      'products.sku',
      'products.name as product',
      'products.contact_id',
      'products.type',
      'stock_products.stock_id',
      'stock_products.product_id',
      'stock_products.sale_price_max',
      'stock_products.quantity',
      'stock_products.purchase_price',
      'stock_products.unit_price',
      'stock_products.sale_price',
      'products.id',
      DB::raw('JSON_OBJECT(
                    "id", units.id,
                    "actual_name", units.actual_name
                ) AS unit'),
      DB::raw('JSON_OBJECT(
                        "id", stock_products.id,
                        "product_id", stock_products.product_id,
                        "stock_id", stock_products.stock_id,
                        "unit_price", stock_products.unit_price,
                        "sku", stock_products.sku,
                        "status", stock_products.status,
                        "purchase_price", stock_products.purchase_price,
                        "unit_price_inc_tax", stock_products.unit_price,
                        "quantity", stock_products.quantity,
                        "image_url", stock_products.image_url,
                        "attribute_first_id", stock_products.attribute_first_id
                ) AS variant'),
      DB::raw('JSON_OBJECT(
                        "id", stocks.id,
                        "stock_name", stocks.stock_name,
                        "stock_type", stocks.stock_type,
                        "business_id", stocks.business_id,
                        "location_id", stocks.location_id,
                        "is_active", stocks.is_active,
                        "is_delete", stocks.is_delete,
                        "is_sync", stocks.is_sync,
                        "is_default", stocks.is_default
                ) AS stock'),
      DB::raw('JSON_OBJECT(
                        "id", tb_attr_1.id,
                        "variant_id", tb_attr_1.variant_id,
                        "title", tb_attr_1.title,
                        "order", tb_attr_1.order
                ) AS attribute_first')
    );

    if ($request['status'] == 'all') {
      $query = Product::with([
        'variants.stock',
        'variants.variantFirst.variant',
        'combo',
        'contact'
      ])->select(
        'products.name',
        'products.sku',
        'products.contact_id',
        'products.name as product',
        'products.type',
        // 'stock_products.*',
        'products.id',
        DB::raw('JSON_OBJECT(
                            "id", stock_products.id,
                            "product_id", stock_products.product_id,
                            "stock_id", stock_products.stock_id,
                            "unit_price", stock_products.unit_price,
                            "sku", stock_products.sku,
                            "status", stock_products.status,
                            "purchase_price", stock_products.purchase_price,
                            "unit_price_inc_tax", stock_products.unit_price,
                            "quantity", stock_products.quantity,
                            "image_url", stock_products.image_url,
                            "attribute_first_id", stock_products.attribute_first_id
                    ) AS variant'),
        DB::raw('JSON_OBJECT(
                            "id", stocks.id,
                            "stock_name", stocks.stock_name,
                            "stock_type", stocks.stock_type,
                            "business_id", stocks.business_id,
                            "location_id", stocks.location_id,
                            "is_active", stocks.is_active,
                            "is_delete", stocks.is_delete,
                            "is_sync", stocks.is_sync,
                            "is_default", stocks.is_default
                    ) AS stock'),
        DB::raw('JSON_OBJECT(
                            "id", tb_attr_1.id,
                            "variant_id", tb_attr_1.variant_id,
                            "title", tb_attr_1.title,
                            "order", tb_attr_1.order
                    ) AS attribute_first')
      );
      $query->leftJoinStockProductsCondition()
        ->leftJoin('stocks', function ($join) {
          $join->on('stocks.id', '=', 'stock_products.stock_id');
        })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->where('products.contact_id', '=', $request['contact_id'])
        ->where('products.business_id', $business_id);
    } else {
      $query->leftJoinStockProductsCondition()
        ->leftJoin('stocks', function ($join) {
          $join->on('stocks.id', '=', 'stock_products.stock_id');
        })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
        ->leftJoin('purchase_lines', 'purchase_lines.product_id', '=', 'products.id')
        ->leftJoin('purchase_line_children', function ($join) {
          $join->on('purchase_line_children.parent_id', '=', 'purchase_lines.id');
        })
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->leftJoin('transactions', 'transactions.id', '=', 'purchase_lines.transaction_id')
        ->where('products.contact_id', '=', $request['contact_id'])
        ->where('transactions.status', '=', 'approve')
        ->where('transactions.type', '=', 'purchase')
        ->where('products.business_id', $business_id)
        ->groupBy(['products.id', 'purchase_line_children.stock_id', 'purchase_line_children.attribute_first_id']);
    }
    if (!empty($request['keyword'])) {
      $keyword = $request['keyword'];
      $query->where(function ($query) use ($keyword) {
        $query->where('products.sku', 'LIKE', "%$keyword%")
          ->orWhere('products.name', 'LIKE', "%$keyword%");
      });
    }
    $query->orderBy('products.created_at', 'desc');
    $data = $query
      ->paginate($request['limit']);
    return $this->transformerSellConditionWithVariantCombo($data);
  }

  protected function getBySell($business_id, $request)
  {
    $query = Product::with([
      'variants.stock',
      'variants.variantFirst.variant',
      'combo',
      'contact'
    ])->select(
      'stock_products.product_id',
      'stock_products.sale_price_max',
      'stock_products.sale_price',
      'stock_products.purchase_price',
      'products.name',
      'products.name as product',
      'products.sku',
      'products.type',
      'products.image',
      'products.contact_id',
      'transaction_sell_lines.unit_price',
      'transaction_sell_lines.unit_price_before_discount',
      'stock_products.quantity',
      'transactions.final_total',
      'transactions.created_at',
      'products.id',
      DB::raw('JSON_OBJECT(
                "id", units.id,
                "actual_name", units.actual_name
            ) AS unit'),
      DB::raw('JSON_OBJECT(
                    "id", stock_products.id,
                    "product_id", stock_products.product_id,
                    "stock_id", stock_products.stock_id,
                    "unit_price", transaction_sell_lines.unit_price,
                    "sku", stock_products.sku,
                    "status", stock_products.status,
                    "purchase_price", stock_products.purchase_price,
                    "unit_price_inc_tax", stock_products.unit_price,
                    "quantity", stock_products.quantity,
                    "image_url", stock_products.image_url,
                    "attribute_first_id", stock_products.attribute_first_id
            ) AS variant'),
      DB::raw('JSON_OBJECT(
                    "id", tb_attr_1.id,
                    "variant_id", tb_attr_1.variant_id,
                    "title", tb_attr_1.title,
                    "order", tb_attr_1.order
            ) AS attribute_first')
    );
    if ($request['status'] != 'all' && $request['type'] == 'sell-return' && !isset($request['order_id'])) {
      if (Customer::where('id', $request['contact_id'])->value('is_default') == 1) {
        return [];
      }
      $query->leftJoin('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
        ->leftJoin('transactions', 'transactions.id', '=', 'transaction_sell_lines.transaction_id')
        ->leftJoin('stock_products', 'stock_products.product_id', '=', 'products.id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->where('transactions.contact_id', '=', $request['contact_id'])
        ->where('transactions.type', '=', 'sell')
        ->where('transactions.status', '=', 'approve')
        ->where('stock_products.status', '=', 'approve')
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->where('products.business_id', $business_id)
        ->orderBy('transactions.created_at', 'desc');

      if (!empty($request['keyword'])) {
        $keyword = $request['keyword'];
        $query->where(function ($query) use ($keyword) {
          $query->where('products.sku', 'LIKE', "%$keyword%")
            ->orWhere('products.name', 'LIKE', "%$keyword%");
        });
      }
      $data = $query->get()->toArray();
      $dataResult = $this->customData($data);
      $helper = new Helper();
      return $helper->paginateCustom(collect($dataResult));
    } else if ($request['status'] != 'all' && $request['type'] == 'sell' || ($request['type'] == 'sell-return' && isset($request['order_id']))) {

      if ($request['status'] != 'all' && $request['type'] == 'sell') {
        if (Customer::where('id', $request['contact_id'])->value('is_default') == 1) {
          return [];
        }
        //
        $sqlLatestPriceQuote = 'SELECT * FROM transactions WHERE business_id = ?  AND contact_id = ? AND type = "price_quote" ORDER BY created_at DESC LIMIT 1';
        $sqlLatestSell = 'SELECT * FROM transactions WHERE business_id = ?  AND contact_id = ? AND type = "sell" AND status = "approve" ORDER BY created_at DESC LIMIT 1';
        //

        $lLatestPriceQuote = DB::select($sqlLatestPriceQuote, [$business_id, $request['contact_id']]);
        $latestSell = DB::select($sqlLatestSell, [$business_id, $request['contact_id']]);
        $sqlConditionGetLatest = '';
        if (!empty($lLatestPriceQuote) && !empty($latestSell)) {
          $latestPriceQuoteCreatedAt = $lLatestPriceQuote[0]->created_at;
          $latestSellCreatedAt = $latestSell[0]->created_at;
          $priceQuoteTime = Carbon::parse($latestPriceQuoteCreatedAt);
          $sellTime = Carbon::parse($latestSellCreatedAt);
          $sqlConditionGetLatest = $priceQuoteTime->greaterThan($sellTime) ? $sqlLatestPriceQuote : $sqlLatestSell;
        } elseif (empty($lLatestPriceQuote) && !empty($latestSell)) {
          $sqlConditionGetLatest = $sqlLatestSell;
        } elseif (!empty($lLatestPriceQuote) && empty($latestSell)) {
          $sqlConditionGetLatest = $sqlLatestPriceQuote;
        } else {
          return [];
        }
        $arrayLeftJoinLatestCondition = [
          DB::raw('(' . $sqlConditionGetLatest . ') as transactions'),
          'transactions.id',
          '=',
          'transaction_sell_lines.transaction_id'
        ];
        //
        $query->leftJoinStockProductsCondition()
          ->leftJoin('transaction_sell_lines', function ($join) {
            $join->on('transaction_sell_lines.product_id', '=', 'products.id');
            $join->on('transaction_sell_lines.product_type', '=', 'products.type');
          });

        $query->leftJoin(
          ...$arrayLeftJoinLatestCondition
        )->setBindings([$business_id, $request['contact_id']])
          ->whereNotNull('transactions.id');

        $query->leftJoin('stocks', function ($join) {
          $join->on('stocks.id', '=', 'stock_products.stock_id');
        })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
          ->leftJoin('units', 'units.id', '=', 'products.unit_id');
        $query->orderBy('transaction_sell_lines.created_at', 'desc');
        $query->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
          ->where('products.business_id', $business_id);

        //
        $query->addSelect(['transactions.id as transaction_id', 'stock_products.stock_id']);

        if (isset(request()->except_product_ids) && request()->except_product_ids) {
          $exceptProductIds = explode(",", request()->except_product_ids);
          $query->whereNotIn('products.id', $exceptProductIds);
        }
      } else {
        if (Customer::where('id', $request['contact_id'])->value('is_default') == 1) {
          return [];
        }
        $query->leftJoin('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
          ->leftJoin('transactions', 'transactions.id', '=', 'transaction_sell_lines.transaction_id')
          ->leftJoinStockProductsCondition()
          ->leftJoin('stocks', function ($join) {
            $join->on('stocks.id', '=', 'stock_products.stock_id');
          })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
          ->leftJoin('units', 'units.id', '=', 'products.unit_id')
          ->where('transactions.contact_id', '=', $request['contact_id'])
          ->where('stock_products.status', '=', 'approve')
          ->where('transaction_sell_lines.quantity_sold', '!=', 0)
          ->where(function ($query) {
            $query->where('transactions.type', '=', 'price_quote')
              ->orWhere(function ($query) {
                $query->where('transactions.type', '=', 'sell')
                  ->where('transactions.status', '=', 'approve');
              });
          })
          ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
          ->where('products.business_id', $business_id);
        if (isset($request['order_id'])) {
          $query->where('transactions.id', '=', $request['order_id']);
        }
      }


      $query->orderBy('transactions.created_at', 'desc');
      if (!empty($request['keyword'])) {
        $keyword = $request['keyword'];
        $query->where(function ($query) use ($keyword) {
          $query->where('products.sku', 'LIKE', "%$keyword%")
            ->orWhere('products.name', 'LIKE', "%$keyword%");
        });
      }
      $data = $query->get()->map(function ($item) {
        $item->stock =  Stock::where('id', $item->stock_id)->first();
        return $item;
      });
      $dataResult = $this->customData($data);
      $helper = new Helper();
      $response =  $helper->paginateCustom(collect($dataResult));
      return $this->transformerSellConditionWithVariantCombo($response);
    } else if ($request['status'] != 'all' && $request['type'] == 'price-quote') {
      if (Customer::where('id', $request['contact_id'])->value('is_default') == 1) {
        return [];
      }
      $query
        ->leftJoinStockProductsCondition()
        ->leftJoin('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
        ->leftJoin('transaction_sell_line_children', 'transaction_sell_line_children.parent_id', '=', 'transaction_sell_lines.id')
        ->leftJoin('transactions', 'transactions.id', '=', 'transaction_sell_lines.transaction_id')
        ->leftJoin('stocks', function ($join) {
          $join->on('stocks.id', '=', 'stock_products.stock_id');
        })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->where('transactions.contact_id', '=', $request['contact_id'])
        ->where('transactions.type', '=', 'price_quote')
        ->whereNull('transaction_sell_lines.parent_sell_line_id')
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->where('products.business_id', $business_id)
        ->join(DB::raw('(SELECT MAX(tsl.id) as max_id, tsl.product_id, tscl.stock_id, tscl.attribute_first_id
                        FROM transaction_sell_lines tsl
                                LEFT JOIN transaction_sell_line_children tscl ON tscl.parent_id = tsl.id
                                LEFT JOIN transactions t ON t.id = tsl.transaction_id
                        WHERE t.contact_id = ' . $request['contact_id'] . '
                                AND t.type = "price_quote"
                                AND tsl.parent_sell_line_id IS NULL
                                AND t.business_id = ' . $business_id . '
                        GROUP BY tsl.product_id, tscl.stock_id, tscl.attribute_first_id) as subquery'), function ($join) {
          $join->on('transaction_sell_lines.id', '=', 'subquery.max_id');
        })
        ->addSelect([
          'transaction_sell_lines.id as transaction_sell_line_id',
          'stock_products.stock_id',
          'stock_products.unit_price as unit_price_inc_tax',
          'transactions.id as transaction_id',
          'stock_products.attribute_first_id',
        ])->groupBy([
          'stock_products.product_id',
          'stock_products.stock_id',
          'stock_products.attribute_first_id'
        ]);

      $query->orderBy('transactions.created_at', 'desc');
      if (!empty($request['keyword'])) {
        $keyword = $request['keyword'];
        $query->where(function ($query) use ($keyword) {
          $query->where('products.sku', 'LIKE', "%$keyword%")
            ->orWhere('products.name', 'LIKE', "%$keyword%");
        });
      }

      if (isset(request()->except_product_ids) && request()->except_product_ids) {
        $exceptProductIds = explode(",", request()->except_product_ids);
        $query->whereNotIn('products.id', $exceptProductIds);
      }

      $data = $query->get()->map(function ($item) {
        $item->stock =  Stock::where('id', $item->stock_id)->first();
        return $item;
      });

      if (isset($request['get_in_transform'])) {
        return  $data;
      }
      $dataResult = $data;
      $helper = new Helper();
      $response =  $helper->paginateCustom(collect($dataResult));
      return $this->transformerSellConditionWithVariantCombo($response);
    } else {
      $query = Product::with([
        'variants.stock',
        'variants.variantFirst.variant',
        'combo',
        'contact'
      ])->select(
        'products.name',
        'products.sku',
        'products.contact_id',
        'products.name as product',
        'products.id',
        'products.type',
        DB::raw('JSON_OBJECT(
                    "id", units.id,
                    "actual_name", units.actual_name
                ) AS unit'),
        DB::raw('JSON_OBJECT(
                        "id", stock_products.id,
                        "product_id", stock_products.product_id,
                        "stock_id", stock_products.stock_id,
                        "unit_price", stock_products.unit_price,
                        "sku", stock_products.sku,
                        "status", stock_products.status,
                        "purchase_price", stock_products.purchase_price,
                        "unit_price_inc_tax", stock_products.unit_price,
                        "quantity", stock_products.quantity,
                        "image_url", stock_products.image_url,
                        "attribute_first_id", stock_products.attribute_first_id
                ) AS variant'),
        DB::raw('JSON_OBJECT(
                        "id", stocks.id,
                        "stock_name", stocks.stock_name,
                        "stock_type", stocks.stock_type,
                        "business_id", stocks.business_id,
                        "location_id", stocks.location_id,
                        "is_active", stocks.is_active,
                        "is_delete", stocks.is_delete,
                        "is_sync", stocks.is_sync,
                        "is_default", stocks.is_default
                ) AS stock'),
        DB::raw('JSON_OBJECT(
                        "id", tb_attr_1.id,
                        "variant_id", tb_attr_1.variant_id,
                        "title", tb_attr_1.title,
                        "order", tb_attr_1.order
                ) AS attribute_first')
      );

      $query->leftJoinStockProductsCondition()
        ->leftJoin('stocks', function ($join) {
          $join->on('stocks.id', '=', 'stock_products.stock_id');
        })->leftJoin('crm_variant_attributes as tb_attr_1', 'tb_attr_1.id', '=', 'stock_products.attribute_first_id')
        ->leftJoin('crm_customers', 'crm_customers.id', '=', 'products.contact_id')
        ->leftJoin('units', 'units.id', '=', 'products.unit_id')
        ->where('products.business_id', $business_id)
        ->addSelect([
          'stock_products.status as status_stock_product',
          'stock_products.stock_id',
          'stock_products.unit_price',
          DB::raw('CASE WHEN products.type = "combo" THEN stock_products.combo_unit_price ELSE stock_products.unit_price END as unit_price_inc_tax')
        ]);
      $query->orderBy('products.created_at', 'desc');
      if (!empty($request['keyword'])) {
        $keyword = $request['keyword'];
        $query->where(function ($query) use ($keyword) {
          $query->where('products.sku', 'LIKE', "%$keyword%")
            ->orWhere('products.name', 'LIKE', "%$keyword%");
        });
      }

      if (isset(request()->except_product_ids) && request()->except_product_ids) {
        $exceptProductIds = explode(",", request()->except_product_ids);
        $query->whereNotIn('products.id', $exceptProductIds);
      }

      $data = $query->paginate($request['limit']);
      return $this->transformerSellConditionWithVariantCombo($data);
    }
  }

  private  function checkIntersection($a, $b)
  {
    $diff = array_diff($b, $a);
    return empty($diff);
  }

  private function queryStockIdAndStockDefault($mainQuery, $hasStockDefault, $hasStockId, $alwaysHaveStockId)
  {
    $mainQuery->when($hasStockDefault || $alwaysHaveStockId, function ($query) {
      // Lấy stock_id default từ bảng stocks
      $idStockDefault = $this->getStockIdDefault();
      // Thực hiện where với idStockDefault
      $query->where('stock_products.stock_id', $idStockDefault);
    })
      ->when($hasStockId, function ($query) {
        $query->where('stock_products.stock_id', request()->stock_id);
      });
  }

  private function getStockIdDefault()
  {
    $stockProductIds = DB::table('stock_products')
      ->leftJoin('products', 'products.id', '=', 'stock_products.product_id')
      ->where('products.business_id', request()->header('business-id'))
      ->select('stock_products.product_id', 'stock_products.stock_id')
      ->where('stock_products.status', 'approve')
      ->pluck('stock_products.stock_id', 'product_id')
      ->toArray();
    // Lấy stock_id default từ bảng stocks
    $idStockDefault = DB::table('stocks')
      ->where('business_id', request()->header('business-id'))
      ->whereIn('id', array_unique(array_values($stockProductIds)))
      ->where('is_default', 1)
      ->pluck('id')
      ->first();
    return  $idStockDefault;
  }

  private  function getRealSql($query)
  {
    $sql = $query->toSql();
    $bindings = $query->getBindings();

    foreach ($bindings as $binding) {
      // Chuyển đổi giá trị thành dạng chuỗi an toàn cho SQL
      if (is_numeric($binding)) {
        $binding = $binding;
      } else {
        $binding = "'" . addslashes($binding) . "'";
      }
      // Thay thế dấu ? bằng giá trị thực tế
      $sql = preg_replace('/\?/', $binding, $sql, 1);
    }

    return $sql;
  }

  private function transformerSellConditionWithVariantCombo($data)
  {
    $collection = $data->getCollection()->transform(function ($item, $key) use ($data) {
      if (isset(request()->is_append_variant) && request()->is_append_variant == 1) {
        $this->appendsVariant($item);
      }

      $item->variant = json_decode($item->variant);
      $item->stock = json_decode($item->stock);
      $item->attribute_first = json_decode($item->attribute_first);
      $item->stock_products = $item->stock_products->map(function ($item) {
        $item->unit_price_inc_tax = $item->unit_price;
      });
      return $item;
    });

    $collection->transform(function ($item, $key) use ($data) {
      $item->record_number = ($data->currentPage() - 1) * $data->perPage() + $key + 1;
      return $item;
    });

    $data->setCollection($collection);
    return $data;
  }

  private function customData($data)
  {
    $grouped_data = [];
    foreach ($data as $item) {
      $product_id = $item['product_id'];
      // Kiểm tra xem product_id đã tồn tại trong mảng nhóm chưa
      if (!isset($grouped_data[$product_id])) {
        // Nếu chưa tồn tại, thì tạo một phần tử mới với product_id là key
        $grouped_data[$product_id] = [];
      }
      // Kiểm tra xem created_at của đối tượng hiện tại có lớn hơn created_at của đối tượng đã lưu trong nhóm không
      // Nếu có, thì thêm đối tượng vào mảng nhóm
      if (!isset($grouped_data[$product_id][0]) || strtotime($item['created_at']) > strtotime($grouped_data[$product_id][0]['created_at'])) {
        $grouped_data[$product_id] = [$item];
      }
    }
    // Định dạng lại mảng dữ liệu để trả về dạng tùy chỉnh
    $final_data = [];
    foreach ($grouped_data as $product_id => $items) {
      $final_data = array_merge($final_data, $items);
    }
    return $final_data;
  }
}
