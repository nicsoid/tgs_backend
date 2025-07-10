// src/pages/CreatePost.js

import React, { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import axios from "axios";
import {
  CalendarIcon,
  PhotographIcon,
  CurrencyDollarIcon,
  XIcon,
} from "@heroicons/react/outline";
import { format } from "date-fns";
import { useTranslation } from "react-i18next";
import UsageAlert from "../components/UsageAlert";

const CreatePost = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [groups, setGroups] = useState([]);
  const [usage, setUsage] = useState(null);
  const [plan, setPlan] = useState(null);
  const [formData, setFormData] = useState({
    group_id: "",
    text: "",
    schedule_times: [""],
    media: [],
    advertiser_username: "",
    amount_paid: "",
    currency: "USD",
  });
  const [submitting, setSubmitting] = useState(false);
  const [currencies, setCurrencies] = useState([]);

  useEffect(() => {
    fetchGroups();
    fetchUsageStats();
    fetchCurrencies();
  }, []);

  const fetchGroups = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/groups`
      );
      setGroups(response.data);
    } catch (error) {
      console.error("Failed to fetch groups:", error);
    }
  };

  const fetchUsageStats = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/usage/stats`
      );
      setUsage(response.data.usage);
      setPlan(response.data.plan);
    } catch (error) {
      console.error("Failed to fetch usage stats:", error);
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
    // Check if adding another schedule time would exceed limits
    if (usage && plan) {
      const currentMessageCount = usage.messages.used;
      const scheduledCount = formData.schedule_times.filter(
        (time) => time
      ).length;
      const totalAfterAdd = currentMessageCount + scheduledCount + 1;

      if (totalAfterAdd > plan.limits.messages_per_month) {
        alert(
          t("message_limit_would_exceed", {
            remaining: plan.limits.messages_per_month - currentMessageCount,
          })
        );
        return;
      }
    }

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

  const handleMediaChange = (e) => {
    const files = Array.from(e.target.files);
    setFormData((prev) => ({
      ...prev,
      media: [...prev.media, ...files],
    }));
  };

  const removeMedia = (index) => {
    setFormData((prev) => ({
      ...prev,
      media: prev.media.filter((_, i) => i !== index),
    }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);

    const formDataToSend = new FormData();
    formDataToSend.append("group_id", formData.group_id);
    formDataToSend.append("text", formData.text);

    // Filter out empty schedule times
    const validScheduleTimes = formData.schedule_times.filter((time) => time);
    validScheduleTimes.forEach((time, index) => {
      formDataToSend.append(`schedule_times[${index}]`, time);
    });

    formData.media.forEach((file, index) => {
      formDataToSend.append(`media[${index}]`, file);
    });

    formDataToSend.append("advertiser_username", formData.advertiser_username);
    formDataToSend.append("amount_paid", formData.amount_paid);
    formDataToSend.append("currency", formData.currency);

    try {
      await axios.post(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts`,
        formDataToSend,
        {
          headers: {
            "Content-Type": "multipart/form-data",
          },
        }
      );
      alert(t("post_scheduled_successfully"));
      navigate("/posts");
    } catch (error) {
      console.error("Failed to create post:", error);
      if (error.response?.status === 403) {
        alert(error.response.data.message);
      } else {
        alert(
          t("failed_to_schedule_post") +
            " " +
            (error.response?.data?.message || "")
        );
      }
    } finally {
      setSubmitting(false);
    }
  };

  const getRemainingMessages = () => {
    if (!usage || !plan) return null;
    return plan.limits.messages_per_month - usage.messages.used;
  };

  const getMinDateTime = () => {
    const now = new Date();
    now.setMinutes(now.getMinutes() + 5); // Minimum 5 minutes from now
    return now.toISOString().slice(0, 16);
  };

  return (
    <div className="max-w-3xl mx-auto">
      {usage && plan && <UsageAlert usage={usage} plan={plan} />}

      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("schedule_new_post")}
        </h1>
        {getRemainingMessages() !== null && (
          <p className="text-sm text-gray-500 mt-1">
            {t("messages_remaining_this_month", {
              count: getRemainingMessages(),
            })}
          </p>
        )}
      </div>

      <form
        onSubmit={handleSubmit}
        className="space-y-6 bg-white shadow px-6 py-8 rounded-lg"
      >
        {/* Group Selection */}
        <div>
          <label
            htmlFor="group_id"
            className="block text-sm font-medium text-gray-700"
          >
            {t("select_group")}
          </label>
          <select
            id="group_id"
            name="group_id"
            value={formData.group_id}
            onChange={handleChange}
            required
            className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          >
            <option value="">{t("choose_a_group")}</option>
            {groups.map((group) => (
              <option key={group._id} value={group._id}>
                {group.title}
              </option>
            ))}
          </select>
        </div>

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
            disabled={
              getRemainingMessages() !== null &&
              formData.schedule_times.filter((t) => t).length >=
                getRemainingMessages()
            }
            className="mt-2 inline-flex items-center px-3 py-1 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <CalendarIcon className="mr-1 h-4 w-4" />
            {t("add_another_time")}
          </button>
        </div>

        {/* Media Upload */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t("media_files")} ({t("optional")})
          </label>
          <div className="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
            <div className="space-y-1 text-center">
              <PhotographIcon className="mx-auto h-12 w-12 text-gray-400" />
              <div className="flex text-sm text-gray-600">
                <label
                  htmlFor="media"
                  className="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500"
                >
                  <span>{t("upload_files")}</span>
                  <input
                    id="media"
                    name="media"
                    type="file"
                    multiple
                    accept="image/*,video/*"
                    onChange={handleMediaChange}
                    className="sr-only"
                  />
                </label>
                <p className="pl-1">{t("or_drag_and_drop")}</p>
              </div>
              <p className="text-xs text-gray-500">{t("file_size_limit")}</p>
            </div>
          </div>
          {formData.media.length > 0 && (
            <div className="mt-4">
              <h4 className="text-sm font-medium text-gray-900">
                {t("selected_files")}:
              </h4>
              <ul className="mt-2 divide-y divide-gray-200">
                {formData.media.map((file, index) => (
                  <li
                    key={index}
                    className="py-2 flex justify-between items-center"
                  >
                    <span className="text-sm text-gray-600">{file.name}</span>
                    <button
                      type="button"
                      onClick={() => removeMedia(index)}
                      className="text-red-600 hover:text-red-800 text-sm"
                    >
                      {t("remove")}
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
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
            disabled={submitting || getRemainingMessages() === 0}
            className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {submitting ? t("scheduling") : t("schedule_post")}
          </button>
        </div>
      </form>
    </div>
  );
};

export default CreatePost;
