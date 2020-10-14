<?php

namespace FondOfSpryker\Yves\GoogleTagManager\Business\Model\DataLayer;

use Generated\Shared\Transfer\ItemTransfer;
use Generated\Shared\Transfer\OrderTransfer;
use Generated\Shared\Transfer\ProductViewTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Spryker\Client\Product\ProductClientInterface;
use Spryker\Shared\Config\Config;
use Spryker\Shared\Money\Dependency\Plugin\MoneyPluginInterface;
use Spryker\Shared\Shipment\ShipmentConstants;
use Spryker\Shared\Tax\TaxConstants;
use Spryker\Zed\Tax\Business\Model\PriceCalculationHelperInterface;

class VariableBuilder implements VariableBuilderInterface
{
    const PAGE_TYPE_CATEGORY = "category";
    const PAGE_TYPE_CART = "cart";
    const PAGE_TYPE_HOME = "home";
    const PAGE_TYPE_ORDER = "order";
    const PAGE_TYPE_OTHER = "other";
    const PAGE_TYPE_PRODUCT = "product";

    const TRANSACTION_ENTITY_QUOTE = 'QUOTE';
    const TRANSACTION_ENTITY_ORDER = 'ORDER';

    /**
     * @var \Spryker\Shared\Money\Dependency\Plugin\MoneyPluginInterface
     */
    protected $moneyPlugin;

    /**
     * @var \Spryker\Client\Product\ProductClientInterface
     */
    protected $productClient;

    /**
     * @var \Spryker\Zed\Tax\Business\Model\PriceCalculationHelperInterface
     */
    protected $priceCalculationHelper;

    /**
     * VariableBuilder constructor.
     *
     * @param \Spryker\Shared\Money\Dependency\Plugin\MoneyPluginInterface $moneyPlugin
     * @param \Spryker\Zed\Tax\Business\Model\PriceCalculationHelperInterface
     * @param \Spryker\Client\Product\ProductClientInterface $productClient
     */
    public function __construct(
        MoneyPluginInterface $moneyPlugin,
        PriceCalculationHelperInterface $priceCalculationHelper,
        ProductClientInterface $productClient
    ) {
        $this->moneyPlugin = $moneyPlugin;
        $this->productClient = $productClient;
        $this->priceCalculationHelper = $priceCalculationHelper;
    }

    /**
     * @param string $page
     *
     * @return array
     */
    public function getDefaultVariables($page): array
    {
        return [
            'pageType' => $page,
        ];
    }

    /**
     * @param \Generated\Shared\Transfer\ProductViewTransfer $product
     *
     * @return array
     */
    public function getProductVariables(ProductViewTransfer $product): array
    {
        return [
            'productId' => $product->getIdProductAbstract(),
            'productName' => $product->getName(),
            'productSku' => $product->getSku(),
            'productPrice' => $this->formatPrice($product->getPrice()),
            'productPriceExcludingTax' => $this->formatPrice($this->priceCalculationHelper->getNetValueFromPrice($product->getPrice(), Config::get(TaxConstants::DEFAULT_TAX_RATE))),
            'productTax' => $this->priceCalculationHelper->getTaxValueFromPrice($product->getPrice(), Config::get(TaxConstants::DEFAULT_TAX_RATE)),
            'productTaxRate' => Config::get(TaxConstants::DEFAULT_TAX_RATE),
        ];
    }

    /**
     * @param array $category
     * @param array $products
     *
     * @return array
     */
    public function getCategoryVariables(array $category, array $products): array
    {
        $categoryProducts = [];
        $productSkus = [];

        foreach ($products as $product) {
            $productSkus[] = $product['abstract_sku'];
            $categoryProducts[] = [
                'id' => $product['id_product_abstract'],
                'name' => $product['abstract_name'],
                'sku' => $product['abstract_sku'],
                'price' => $this->formatPrice($product['price']),
            ];
        }

        return [
            'categoryId' => $category['id_category'],
            'categoryName' => $category['name'],
            'categorySize' => count($categoryProducts),
            'categoryProducts' => $categoryProducts,
            'products' => $productSkus,
        ];
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     * @param string $sessionId
     *
     * @return array
     */
    public function getQuoteVariables(QuoteTransfer $quoteTransfer, string $sessionId): array
    {
        $transactionProducts = [];
        $transactionProductsSkus = [];
        $total = $quoteTransfer->getTotals()->getGrandTotal();
        $totalWithoutShippingAmount = 0;
        $quoteItems = $quoteTransfer->getItems();

        if (count($quoteItems) > 0) {
            foreach ($quoteItems as $item) {
                $transactionProductsSkus[] = $item->getSku();
                $transactionProducts[] = $this->getProductForTransaction($item);
            }
        }

        if ($quoteTransfer->getShipment()) {
            $totalWithoutShippingAmount = $total - $quoteTransfer->getShipment()->getMethod()->getStoreCurrencyPrice();
        }

        return [
            'transactionEntity' => self::TRANSACTION_ENTITY_QUOTE,
            'transactionId' => $sessionId,
            'transactionAffiliation' => $quoteTransfer->getStore()->getName(),
            'transactionTotal' => $this->formatPrice($total),
            'transactionTotalWithoutShippingAmount' => $this->formatPrice($totalWithoutShippingAmount),
            'transactionTax' => $this->formatPrice($quoteTransfer->getTotals()->getTaxTotal()->getAmount()),
            'transactionProducts' => $transactionProducts,
            'transactionProductsSkus' => $transactionProductsSkus,
        ];
    }

    /**
     * @param \Generated\Shared\Transfer\OrderTransfer $orderTransfer
     *
     * @return array
     */
    public function getOrderVariables(OrderTransfer $orderTransfer)
    {
        $transactionProducts = [];
        $transactionProductsSkus = [];
        $shipmmentMethods = [];
        $paymentMethods = [];
        $expenses = [];
        $orderItems = $orderTransfer->getItems();

        if (count($orderItems) > 0) {
            foreach ($orderItems as $item) {
                $transactionProductsSkus[] = $item->getSku();
                $transactionProducts[] = $this->getProductForTransaction($item);
            }
        }

        if (count($orderTransfer->getShipmentMethods()) > 0) {
            foreach ($orderTransfer->getShipmentMethods() as $shipmment) {
                $shipmmentMethods[] = $shipmment->getName();
            }
        }

        if (count($orderTransfer->getPayments()) > 0) {
            foreach ($orderTransfer->getPayments() as $payment) {
                $paymentMethods[] = $payment->getPaymentMethod();
            }
        }

        if (count($orderTransfer->getExpenses())) {
            foreach ($orderTransfer->getExpenses() as $expense) {
                $expenses[$expense->getType()] = (!array_key_exists($expense->getType(), $expenses)) ? $expense->getUnitPrice() : $expenses[$expense->getType()] + $expense->getUnitPrice();
            }
        }

        if (array_key_exists(ShipmentConstants::SHIPMENT_EXPENSE_TYPE, $expenses)) {
            $transactionTotalWithoutShippingAmount = $orderTransfer->getTotals()->getGrandTotal() - $expenses[ShipmentConstants::SHIPMENT_EXPENSE_TYPE];
        } else {
            $transactionTotalWithoutShippingAmount = $orderTransfer->getTotals()->getGrandTotal();
        }

        return [
            'transactionEntity' => self::TRANSACTION_ENTITY_ORDER,
            'transactionId' => $orderTransfer->getOrderReference(),
            'transactionDate' => $orderTransfer->getCreatedAt(),
            'transactionAffiliation' => $orderTransfer->getStore(),
            'transactionTotal' => $this->formatPrice($orderTransfer->getTotals()->getGrandTotal()),
            'transactionTotalWithoutShippingAmount' => $this->formatPrice($transactionTotalWithoutShippingAmount),
            'transactionSubtotal' => $this->formatPrice($orderTransfer->getTotals()->getSubtotal()),
            'transactionTax' => $this->formatPrice($orderTransfer->getTotals()->getTaxTotal()->getAmount()),
            'transactionShipping' => implode('-', $shipmmentMethods),
            'transactionPayment' => implode('-', $paymentMethods),
            'transactionCurrency' => $orderTransfer->getCurrencyIsoCode(),
            'transactionProducts' => $transactionProducts,
            'transactionProductsSkus' => $transactionProductsSkus,
            'customerEmail' => $orderTransfer->getBillingAddress()->getEmail(),
        ];
    }

    /**
     * @param \Generated\Shared\Transfer\ItemTransfer $product
     *
     * @return array
     */
    protected function getProductForTransaction(ItemTransfer $product)
    {
        return [
            'id' => $product->getIdProductAbstract(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $this->formatPrice($product->getUnitPrice()),
            'priceexcludingtax' => ($product->getUnitNetPrice()) ? $this->formatPrice($product->getUnitNetPrice()) :  $this->formatPrice($product->getUnitPrice() - $product->getUnitTaxAmount()),
            'tax' => $this->formatPrice($product->getUnitTaxAmount()),
            'taxrate' => $product->getTaxRate(),
            'quantity' => $product->getQuantity(),
        ];
    }

    /**
     * @param int $amount
     *
     * @return float
     */
    protected function formatPrice($amount)
    {
        return $this->moneyPlugin->convertIntegerToDecimal($amount);
    }
}
