<?php

namespace App\Module\Sale\Model\Transformers;

use App\Module\Warehouse\Repository\ProductRepository;
use Illuminate\Support\Facades\DB;

class SellLineTransformer extends ProductRepository
{
  public function transform($transaction)
  {
    $transaction->sell_lines->map(function ($item) use ($transaction) {
      if (is_null($item->parent_sell_line_id)) {
        $item->stock = ($item->children) ? $item->children->stock : null;
        if ($item->product_type === 'single' || $item->product_type === 'combo') {
          $image_url_format = null;
          if (strpos($item->children->image_url, env('PORTAL_URL')) !== false) {
            $image_url_format = str_replace(env('PORTAL_URL') . "/", "", $item->children->image_url);
          } else {
            $image_url_format = $item->children->image_url;
          }
          $item->image_url = env('PORTAL_URL') . '/' . $image_url_format;
        }
        //
        $item->stock_products = $item->product->stock_products;

        $item->stock_products->map(function ($itemSub) use ($item, $transaction) {
          if ($itemSub->id == $item->stock_product_id) {
            $itemSub->unit_price_inc_tax = $item->unit_price_inc_tax;
            $itemSub->unit_price = $item->unit_price;
          } else {
            if ($transaction->contact) {
              $objectRequestByQuotePrice = [
                'contact_id' => $transaction->contact->id,
                'is_append_variant' => 1,
                'is_stock_default' => 1,
                'status' => '!all',
                'type' => $transaction->type === 'price_quote' ? 'price-quote' : 'sell',
                'get_in_transform' => 1
              ];

              $dataByQuotePrice = $this->getBySell(
                request()->header('business-id'),
                $objectRequestByQuotePrice
              );
              $getProductInQuotation = null;
              if (!empty($dataByQuotePrice)) {
                $getProductInQuotation = $dataByQuotePrice
                  ->where('product_id', $itemSub->product_id)
                  ->where('stock_id', $itemSub->stock_id)
                  ->first();
              }
              $latestQuotation = false;
              $unit_price_inc_tax = 0;
              $unit_price = 0;
              $maxIdTransactionFind = null;
              //
              if ($getProductInQuotation) {
                $maxIdQuotationPriceQuote = DB::table('transactions')
                  ->where('contact_id', $transaction->contact->id)
                  ->where('type', 'price_quote')
                  ->max('id');
                if ($transaction->type == 'price_quote') {
                  $maxIdTransactionFind = $maxIdQuotationPriceQuote;
                }
                if ($transaction->type == 'sell') {
                  $maxIdQuotationSell = DB::table('transactions')
                    ->where('contact_id', $transaction->contact->id)
                    ->where('type', 'sell')
                    ->where('status', 'approve')
                    ->max('id');
                  if ($maxIdQuotationPriceQuote >  $maxIdQuotationSell) {
                    $maxIdTransactionFind = $maxIdQuotationPriceQuote;
                  }
                  if ($maxIdQuotationPriceQuote <  $maxIdQuotationSell) {
                    $maxIdTransactionFind = $maxIdQuotationSell;
                  }
                }
                if ((!is_null($maxIdTransactionFind)) && ($maxIdTransactionFind == $getProductInQuotation->transaction_id)) {
                  $latestQuotation = true;
                }

                if ($latestQuotation) {
                  $unit_price_inc_tax =  $getProductInQuotation->type !== 'combo'
                    ?  $getProductInQuotation->unit_price_inc_tax
                    :
                    $getProductInQuotation->combo_unit_price;
                  $unit_price = $getProductInQuotation->unit_price;
                } else {
                  $firstDefault = null;
                  if ($item->product_type === 'single' || $item->product_type === 'combo') {
                    $firstDefault = $this->getListedPrice(
                      $itemSub->product_id,
                      $itemSub->stock_id,
                      false,
                      null
                    );
                  } else {
                    $firstDefault = $this->getListedPrice(
                      $itemSub->product_id,
                      $itemSub->stock_id,
                      true,
                      null
                    );
                  }
                  if ($firstDefault) {
                    $unit_price_inc_tax = $firstDefault['type'] !== 'combo'
                      ? @$firstDefault['unit_price_inc_tax']
                      : @$firstDefault['combo_unit_price'];
                    $unit_price = $firstDefault['type'] !== 'combo'
                      ? @$firstDefault['unit_price']
                      : @$firstDefault['combo_unit_price'];
                  }
                }
              }
              //

              $itemSub->unit_price_inc_tax = $unit_price_inc_tax;
              $itemSub->unit_price = $unit_price;
            }
          }
        });
        //
        if ($item->children_type === 'combo') {
          $this->transformCombo($item);
        } elseif ($item->children_type === 'variable') {
          $this->transformVariable($item);
        }

        if (isset($item->append_variant)) {
          $item->append_variant =  collect($item->append_variant)->map(function ($itemAppendVariant) use ($item, $transaction) {
            if ($itemAppendVariant['id'] == $item->stock_product_id) {
              $itemAppendVariant['unit_price'] = $item->unit_price;
              $itemAppendVariant['unit_price_inc_tax'] = $item->unit_price_inc_tax;
            } else {
              if ($transaction->contact) {
                $objectRequestByQuotePrice = [
                  'contact_id' => $transaction->contact->id,
                  'is_append_variant' => 1,
                  'is_stock_default' => 1,
                  'status' => '!all',
                  'type' => $transaction->type === 'price_quote' ? 'price-quote' : 'sell',
                  'get_in_transform' => 1
                ];

                $dataByQuotePrice = $this->getBySell(
                  request()->header('business-id'),
                  $objectRequestByQuotePrice
                );
                $getProductInQuotation = null;
                if (!empty($dataByQuotePrice)) {
                  $getProductInQuotation = $dataByQuotePrice
                    ->where('product_id', $itemAppendVariant['product_id'])
                    ->where('stock_id', $itemAppendVariant['stock_id'])
                    ->where('attribute_first_id', $itemAppendVariant['attribute_first_id'])
                    ->first();
                }
                $latestQuotationVariant = false;
                $unit_price_inc_tax_variant = 0;
                $unit_price_variant = 0;
                $maxIdTransactionFindForVariant = null;
                //
                if ($getProductInQuotation) {
                  $maxIdQuotationPriceQuote = DB::table('transactions')
                    ->where('contact_id', $transaction->contact->id)
                    ->where('type', 'price_quote')
                    ->max('id');
                  if ($transaction->type == 'price_quote') {
                    $maxIdTransactionFindForVariant = $maxIdQuotationPriceQuote;
                  }
                  if ($transaction->type == 'sell') {
                    $maxIdQuotationSell = DB::table('transactions')
                      ->where('contact_id', $transaction->contact->id)
                      ->where('type', 'sell')
                      ->where('status', 'approve')
                      ->max('id');
                    if ($maxIdQuotationPriceQuote >  $maxIdQuotationSell) {
                      $maxIdTransactionFindForVariant = $maxIdQuotationPriceQuote;
                    }
                    if ($maxIdQuotationPriceQuote <  $maxIdQuotationSell) {
                      $maxIdTransactionFindForVariant = $maxIdQuotationSell;
                    }
                  }
                  if ((!is_null($maxIdTransactionFindForVariant)) && ($maxIdTransactionFindForVariant == $getProductInQuotation->transaction_id)) {
                    $latestQuotationVariant = true;
                  }

                  if ($latestQuotationVariant) {
                    $unit_price_inc_tax_variant =  $getProductInQuotation->unit_price_inc_tax;
                    $unit_price_variant = $getProductInQuotation->unit_price;
                  } else {
                    $firstDefault = $this->getListedPrice(
                      $itemAppendVariant['product_id'],
                      $itemAppendVariant['stock_id'],
                      true,
                      $itemAppendVariant['attribute_first_id']
                    );
                    if ($firstDefault) {
                      $unit_price_inc_tax_variant = @$firstDefault['unit_price_inc_tax'];
                      $unit_price_variant = @$firstDefault['unit_price'];
                    }
                  }
                }
                //

                $itemAppendVariant['unit_price_inc_tax'] = $unit_price_inc_tax_variant;
                $itemAppendVariant['unit_price'] = $unit_price_variant;
              }
            }
            return $itemAppendVariant;
          })->toArray();
        }
      }
    });

    return $transaction;
  }

  private function getListedPrice($product_id, $stock_id, $isVariable = false, $attribute_first_id = null)
  {
    $query = DB::table('stock_products')
      ->leftJoin('products', 'products.id', '=', 'stock_products.product_id')
      ->where('stock_products.product_id', $product_id)
      ->where('stock_products.stock_id', $stock_id)
      ->where('products.business_id', request()->header('business-id'));
    if ($isVariable) {
      $query->where('stock_products.is_main_stock', 0);
      if (!is_null($attribute_first_id)) {
        $query->where('stock_products.attribute_first_id', $attribute_first_id);
      }
    } else {
      $query->where('stock_products.is_main_stock', 1);
    }
    $data = $query->first();
    if ($data) {
      $data = (array) $data;
      $data['unit_price_inc_tax'] = $data['unit_price'];
      return $data;
    }
    return null;
  }

  private function transformCombo($item)
  {
    $item->combo->map(function ($itemCombo) {
      if ($itemCombo->children) {
        $itemCombo->variant_first = $itemCombo->children->attributeFirst;
        $itemCombo->stock = $itemCombo->children->stock;
      }
    });
  }

  private function transformVariable($item)
  {
    if ($item->product && !empty($item->product->variants)) {
      $item->variants = $item->product->variants;

      if (request()->has('is_append_variant') && request()->is_append_variant == 1) {
        $this->appendsVariant($item);
      }
    }

    if ($item->children) {
      $item->attribute_first_id = $item->children->attribute_first_id;
    }
    if (isset(request()->is_append_variant) && request()->is_append_variant == 1) {
      $item->append_variant = $this->appendsProductNotStockDefault($item, true);
    }
  }
}
