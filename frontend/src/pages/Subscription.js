// src/pages/Subscription.js - Fixed Version

import React, { useState, useEffect, useCallback } from "react";
import { useTranslation } from "react-i18next";
import { loadStripe } from "@stripe/stripe-js";
import {
  Elements,
  CardElement,
  useStripe,
  useElements,
} from "@stripe/react-stripe-js";
import axios from "axios";
import { CheckIcon } from "@heroicons/react/solid";
import { CircularProgressbar, buildStyles } from "react-circular-progressbar";
import "react-circular-progressbar/dist/styles.css";

const stripePromise = loadStripe(process.env.REACT_APP_STRIPE_PUBLIC_KEY);

const SubscriptionPage = () => {
  const { t } = useTranslation();
  const [plans, setPlans] = useState([]);
  const [currentPlan, setCurrentPlan] = useState(null);
  const [usage, setUsage] = useState(null);
  const [subscription, setSubscription] = useState(null);

  const fetchPlans = useCallback(async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/subscription/plans`
      );
      setPlans(response.data.plans);
      setCurrentPlan(response.data.current_plan);
      setUsage(response.data.usage);
      setSubscription(response.data.subscription);
    } catch (error) {
      console.error("Failed to fetch plans:", error);
    }
  }, []);

  useEffect(() => {
    fetchPlans();
  }, [fetchPlans]);

  const handleCancelSubscription = async () => {
    if (!window.confirm(t("confirm_cancel_subscription"))) {
      return;
    }

    try {
      await axios.post(
        `${process.env.REACT_APP_API_URL}/api/subscription/cancel`
      );
      alert(t("subscription_cancelled"));
      fetchPlans();
    } catch (error) {
      alert(t("failed_to_cancel_subscription"));
    }
  };

  const handleResumeSubscription = async () => {
    try {
      await axios.post(
        `${process.env.REACT_APP_API_URL}/api/subscription/resume`
      );
      alert(t("subscription_resumed"));
      fetchPlans();
    } catch (error) {
      alert(t("failed_to_resume_subscription"));
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div className="text-center">
        <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">
          {t("subscription_plans")}
        </h2>
        <p className="mt-4 text-xl text-gray-600">
          {t("choose_plan_that_fits")}
        </p>
      </div>

      {/* Current Usage */}
      {usage && currentPlan && (
        <div className="mt-8 bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 mb-4">
            {t("current_usage")}
          </h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="flex items-center space-x-4">
              <div className="w-20 h-20">
                <CircularProgressbar
                  value={usage?.groups_count || 0}
                  maxValue={currentPlan?.limits?.groups || 1}
                  text={`${usage?.groups_count || 0}/${
                    currentPlan?.limits?.groups || 1
                  }`}
                  styles={buildStyles({
                    pathColor: "#4F46E5",
                    textColor: "#1F2937",
                    trailColor: "#E5E7EB",
                  })}
                />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t("groups")}
                </p>
                <p className="text-sm text-gray-500">
                  {t("groups_usage_description")}
                </p>
              </div>
            </div>

            <div className="flex items-center space-x-4">
              <div className="w-20 h-20">
                <CircularProgressbar
                  value={usage?.messages_sent_this_month || 0}
                  maxValue={currentPlan?.limits?.messages_per_month || 3}
                  text={`${usage?.messages_sent_this_month || 0}/${
                    currentPlan?.limits?.messages_per_month || 3
                  }`}
                  styles={buildStyles({
                    pathColor: "#10B981",
                    textColor: "#1F2937",
                    trailColor: "#E5E7EB",
                  })}
                />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t("messages_this_month")}
                </p>
                <p className="text-sm text-gray-500">
                  {t("messages_usage_description")}
                </p>
              </div>
            </div>
          </div>

          {subscription?.cancel_at_period_end && (
            <div className="mt-4 bg-yellow-50 border border-yellow-200 rounded-md p-4">
              <p className="text-sm text-yellow-800">
                {t("subscription_will_cancel", {
                  date: new Date(
                    subscription.current_period_end
                  ).toLocaleDateString(),
                })}
              </p>
              <button
                onClick={handleResumeSubscription}
                className="mt-2 text-sm font-medium text-yellow-800 hover:text-yellow-900"
              >
                {t("resume_subscription")}
              </button>
            </div>
          )}
        </div>
      )}

      {/* Plans */}
      {plans.length > 0 && (
        <div className="mt-12 space-y-4 sm:mt-16 sm:space-y-0 sm:grid sm:grid-cols-3 sm:gap-6 lg:max-w-4xl lg:mx-auto xl:max-w-none xl:mx-0">
          {plans.map((plan) => (
            <div
              key={plan.name}
              className={`rounded-lg shadow-lg divide-y divide-gray-200 ${
                currentPlan?.name === plan.name ? "ring-2 ring-indigo-500" : ""
              }`}
            >
              <div className="p-6">
                <h3 className="text-lg leading-6 font-medium text-gray-900">
                  {plan.display_name}
                </h3>
                <p className="mt-4 text-sm text-gray-500">
                  {plan.name === "free" && t("perfect_for_getting_started")}
                  {plan.name === "pro" && t("great_for_growing_channels")}
                  {plan.name === "ultra" && t("for_power_users")}
                </p>
                <p className="mt-8">
                  <span className="text-4xl font-extrabold text-gray-900">
                    ${plan.price}
                  </span>
                  <span className="text-base font-medium text-gray-500">
                    /{t("month")}
                  </span>
                </p>

                {currentPlan?.name === plan.name ? (
                  <div className="mt-8">
                    <button
                      disabled
                      className="block w-full bg-gray-100 py-2 text-sm font-semibold text-gray-500 text-center rounded-md"
                    >
                      {t("current_plan")}
                    </button>
                    {plan.price > 0 && !subscription?.cancel_at_period_end && (
                      <button
                        onClick={handleCancelSubscription}
                        className="mt-2 block w-full text-sm text-red-600 hover:text-red-800"
                      >
                        {t("cancel_subscription")}
                      </button>
                    )}
                  </div>
                ) : plan.price > 0 ? (
                  <Elements stripe={stripePromise}>
                    <CheckoutForm plan={plan} onSuccess={fetchPlans} />
                  </Elements>
                ) : (
                  <button
                    disabled
                    className="mt-8 block w-full bg-gray-100 py-2 text-sm font-semibold text-gray-500 text-center rounded-md"
                  >
                    {t("free_forever")}
                  </button>
                )}
              </div>
              <div className="pt-6 pb-8 px-6">
                <h4 className="text-xs font-medium text-gray-900 tracking-wide uppercase">
                  {t("whats_included")}
                </h4>
                <ul className="mt-6 space-y-4">
                  {plan.features.map((feature, index) => (
                    <li key={index} className="flex space-x-3">
                      <CheckIcon className="flex-shrink-0 h-5 w-5 text-green-500" />
                      <span className="text-sm text-gray-500">{feature}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Contact for more */}
      <div className="mt-12 text-center">
        <p className="text-base text-gray-500">
          {t("need_more_groups_or_messages")}{" "}
          <a
            href="mailto:support@tgappy.com"
            className="font-medium text-indigo-600 hover:text-indigo-500"
          >
            {t("contact_us")}
          </a>
        </p>
      </div>
    </div>
  );
};

const CheckoutForm = ({ plan, onSuccess }) => {
  const { t } = useTranslation();
  const stripe = useStripe();
  const elements = useElements();
  const [error, setError] = useState(null);
  const [processing, setProcessing] = useState(false);
  const [showPaymentForm, setShowPaymentForm] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();

    if (!stripe || !elements) {
      return;
    }

    setProcessing(true);
    setError(null);

    const { error: stripeError, paymentMethod } =
      await stripe.createPaymentMethod({
        type: "card",
        card: elements.getElement(CardElement),
      });

    if (stripeError) {
      setError(stripeError.message);
      setProcessing(false);
      return;
    }

    try {
      const response = await axios.post(
        `${process.env.REACT_APP_API_URL}/api/subscription/checkout`,
        {
          plan: plan.name,
          payment_method_id: paymentMethod.id,
        }
      );

      if (response.data.subscription) {
        alert(t("subscription_successful"));
        onSuccess();
        setShowPaymentForm(false);
      }
    } catch (error) {
      setError(error.response?.data?.error || t("payment_failed"));
    } finally {
      setProcessing(false);
    }
  };

  if (!showPaymentForm) {
    return (
      <button
        onClick={() => setShowPaymentForm(true)}
        className="mt-8 block w-full bg-indigo-600 border border-indigo-600 rounded-md py-2 text-sm font-semibold text-white text-center hover:bg-indigo-700"
      >
        {t("upgrade_to")} {plan.display_name}
      </button>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="mt-8">
      <div className="border border-gray-300 rounded-md p-4">
        <CardElement
          options={{
            style: {
              base: {
                fontSize: "16px",
                color: "#424770",
                "::placeholder": {
                  color: "#aab7c4",
                },
              },
            },
          }}
        />
      </div>
      {error && <div className="mt-2 text-sm text-red-600">{error}</div>}
      <button
        type="submit"
        disabled={!stripe || processing}
        className="mt-4 block w-full bg-indigo-600 border border-indigo-600 rounded-md py-2 text-sm font-semibold text-white text-center hover:bg-indigo-700 disabled:opacity-50"
      >
        {processing ? t("processing") : t("subscribe")}
      </button>
      <button
        type="button"
        onClick={() => setShowPaymentForm(false)}
        className="mt-2 block w-full text-sm text-gray-600 hover:text-gray-800"
      >
        {t("cancel")}
      </button>
    </form>
  );
};

export default SubscriptionPage;
