// Create src/pages/EditPost.js

import React, { useState, useEffect } from "react";
import { useNavigate, useParams } from "react-router-dom";
import axios from "axios";
import {
  CalendarIcon,
  PhotographIcon,
  CurrencyDollarIcon,
  XIcon,
} from "@heroicons/react/outline";
import { format } from "date-fns";
import { useTranslation } from "react-i18next";

const EditPost = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { postId } = useParams();
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [post, setPost] = useState(null);
  const [currencies, setCurrencies] = useState([]);
  const [formData, setFormData] = useState({
    text: "",
    schedule_times: [""],
    advertiser_username: "",
    amount_paid: "",
    currency: "USD",
  });

  useEffect(() => {
    fetchPost();
    fetchCurrencies();
  }, [postId]);

  const fetchPost = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/${postId}`
      );
      const postData = response.data;
      setPost(postData);

      // Populate form with existing data
      setFormData({
        text: postData.content.text || "",
        schedule_times: postData.schedule_times || [""],
        advertiser_username: postData.advertiser.telegram_username || "",
        amount_paid: postData.advertiser.amount_paid || "",
        currency: postData.advertiser.currency || "USD",
      });
    } catch (error) {
      console.error("Failed to fetch post:", error);
      alert(t("failed_to_load_post"));
      navigate("/posts");
    } finally {
      setLoading(false);
    }
  };

  const fetchCurrencies = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/user/settings`
      );
      setCurrencies(response.data.available_currencies);
    } catch (error) {
      console.error("Failed to fetch currencies:", error);
    }
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({
      ...prev,
      [name]: value,
    }));
  };

  const handleScheduleTimeChange = (index, value) => {
    const newTimes = [...formData.schedule_times];
    newTimes[index] = value;
    setFormData((prev) => ({
      ...prev,
      schedule_times: newTimes,
    }));
  };

  const addScheduleTime = () => {
    setFormData((prev) => ({
      ...prev,
      schedule_times: [...prev.schedule_times, ""],
    }));
  };

  const removeScheduleTime = (index) => {
    const newTimes = formData.schedule_times.filter((_, i) => i !== index);
    setFormData((prev) => ({
      ...prev,
      schedule_times: newTimes.length === 0 ? [""] : newTimes,
    }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);

    const updateData = {
      text: formData.text,
      schedule_times: formData.schedule_times.filter((time) => time),
      advertiser_username: formData.advertiser_username,
      amount_paid: formData.amount_paid,
      currency: formData.currency,
    };

    try {
      await axios.put(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/${postId}`,
        updateData
      );
      alert(t("post_updated_successfully"));
      navigate("/posts");
    } catch (error) {
      console.error("Failed to update post:", error);
      if (error.response?.status === 403) {
        alert(error.response.data.message);
      } else if (error.response?.status === 400) {
        alert(error.response.data.error || t("cannot_update_post"));
      } else {
        alert(
          t("failed_to_update_post") +
            " " +
            (error.response?.data?.message || "")
        );
      }
    } finally {
      setSubmitting(false);
    }
  };

  const getMinDateTime = () => {
    const now = new Date();
    now.setMinutes(now.getMinutes() + 5);
    return now.toISOString().slice(0, 16);
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
      </div>
    );
  }

  if (!post) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500">{t("post_not_found")}</p>
      </div>
    );
  }

  // Don't allow editing if post has started sending
  if (post.status !== "pending") {
    return (
      <div className="max-w-3xl mx-auto">
        <div className="bg-yellow-50 border border-yellow-200 rounded-md p-4">
          <h3 className="text-sm font-medium text-yellow-800">
            {t("cannot_edit_post")}
          </h3>
          <p className="mt-1 text-sm text-yellow-700">
            {t("cannot_edit_post_description")}
          </p>
          <button
            onClick={() => navigate("/posts")}
            className="mt-2 text-sm font-medium text-yellow-800 hover:text-yellow-900"
          >
            ← {t("back_to_posts")}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-3xl mx-auto">
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("edit_post")}
        </h1>
        <p className="text-sm text-gray-500 mt-1">
          {t("editing_post_for")} {post.group?.title}
        </p>
      </div>

      <form
        onSubmit={handleSubmit}
        className="space-y-6 bg-white shadow px-6 py-8 rounded-lg"
      >
        {/* Message Text */}
        <div>
          <label
            htmlFor="text"
            className="block text-sm font-medium text-gray-700"
          >
            {t("message_text")}
          </label>
          <textarea
            id="text"
            name="text"
            rows={4}
            value={formData.text}
            onChange={handleChange}
            required
            className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            placeholder={t("enter_your_message")}
          />
          <p className="mt-1 text-sm text-gray-500">
            {t("supports_html_formatting")}
          </p>
        </div>

        {/* Schedule Times */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t("schedule_times")}
          </label>
          {formData.schedule_times.map((time, index) => (
            <div key={index} className="flex items-center mb-2">
              <input
                type="datetime-local"
                value={time}
                onChange={(e) =>
                  handleScheduleTimeChange(index, e.target.value)
                }
                required
                min={getMinDateTime()}
                className="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              />
              {formData.schedule_times.length > 1 && (
                <button
                  type="button"
                  onClick={() => removeScheduleTime(index)}
                  className="ml-2 inline-flex items-center p-1 border border-transparent rounded-full text-red-600 hover:bg-red-50"
                >
                  <XIcon className="h-5 w-5" />
                </button>
              )}
            </div>
          ))}
          <button
            type="button"
            onClick={addScheduleTime}
            className="mt-2 inline-flex items-center px-3 py-1 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            <CalendarIcon className="mr-1 h-4 w-4" />
            {t("add_another_time")}
          </button>
        </div>

        {/* Advertiser Info */}
        <div className="space-y-4">
          <h3 className="text-sm font-medium text-gray-900">
            {t("advertiser_information")}
          </h3>

          <div>
            <label
              htmlFor="advertiser_username"
              className="block text-sm font-medium text-gray-700"
            >
              {t("advertiser_telegram_username")}
            </label>
            <div className="mt-1 relative rounded-md shadow-sm">
              <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <span className="text-gray-500 sm:text-sm">@</span>
              </div>
              <input
                type="text"
                name="advertiser_username"
                id="advertiser_username"
                value={formData.advertiser_username}
                onChange={handleChange}
                required
                className="pl-7 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                placeholder="username"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label
                htmlFor="amount_paid"
                className="block text-sm font-medium text-gray-700"
              >
                {t("amount_paid")}
              </label>
              <div className="mt-1 relative rounded-md shadow-sm">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <CurrencyDollarIcon className="h-5 w-5 text-gray-400" />
                </div>
                <input
                  type="number"
                  name="amount_paid"
                  id="amount_paid"
                  value={formData.amount_paid}
                  onChange={handleChange}
                  required
                  min="0"
                  step="0.01"
                  className="pl-10 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                  placeholder="0.00"
                />
              </div>
            </div>

            <div>
              <label
                htmlFor="currency"
                className="block text-sm font-medium text-gray-700"
              >
                {t("currency")}
              </label>
              <select
                id="currency"
                name="currency"
                value={formData.currency}
                onChange={handleChange}
                className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              >
                {currencies.map((currency) => (
                  <option key={currency.code} value={currency.code}>
                    {currency.code} - {currency.name}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {/* Submit Buttons */}
        <div className="flex justify-end space-x-3">
          <button
            type="button"
            onClick={() => navigate("/posts")}
            className="py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            {t("cancel")}
          </button>
          <button
            type="submit"
            disabled={submitting}
            className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {submitting ? t("updating") : t("update_post")}
          </button>
        </div>
      </form>
    </div>
  );
};

export default EditPost;
