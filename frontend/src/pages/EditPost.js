// src/pages/EditPost.js - Always Editable Version

import React, { useState, useEffect } from "react";
import { useNavigate, useParams } from "react-router-dom";
import axios from "axios";
import {
  CalendarIcon,
  PhotographIcon,
  CurrencyDollarIcon,
  XIcon,
  TrashIcon,
  CheckIcon,
  ClockIcon,
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
  const [groups, setGroups] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [formData, setFormData] = useState({
    group_ids: [],
    text: "",
    schedule_times: [""],
    advertiser_username: "",
    amount_paid: "",
    currency: "USD",
  });
  const [existingMedia, setExistingMedia] = useState([]);
  const [newMedia, setNewMedia] = useState([]);
  const [keepExistingMedia, setKeepExistingMedia] = useState([]);

  useEffect(() => {
    fetchPost();
    fetchGroups();
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
        group_ids: postData.group_ids || [],
        text: postData.content.text || "",
        schedule_times: postData.schedule_times || [""],
        advertiser_username: postData.advertiser.telegram_username || "",
        amount_paid: postData.advertiser.amount_paid || "",
        currency: postData.advertiser.currency || "USD",
      });

      // Set existing media
      const media = postData.content.media || [];
      setExistingMedia(media);
      // Initially, keep all existing media
      setKeepExistingMedia(media.map((_, index) => index));
    } catch (error) {
      console.error("Failed to fetch post:", error);
      alert(t("failed_to_load_post"));
      navigate("/posts");
    } finally {
      setLoading(false);
    }
  };

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

  const handleGroupSelection = (groupId) => {
    setFormData((prev) => {
      const isSelected = prev.group_ids.includes(groupId);
      const newGroupIds = isSelected
        ? prev.group_ids.filter((id) => id !== groupId)
        : [...prev.group_ids, groupId];

      return {
        ...prev,
        group_ids: newGroupIds,
      };
    });
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

  const handleNewMediaChange = (e) => {
    const files = Array.from(e.target.files);
    setNewMedia([...newMedia, ...files]);
  };

  const removeNewMedia = (index) => {
    setNewMedia(newMedia.filter((_, i) => i !== index));
  };

  const toggleKeepExistingMedia = (index) => {
    setKeepExistingMedia((prev) => {
      if (prev.includes(index)) {
        return prev.filter((i) => i !== index);
      } else {
        return [...prev, index];
      }
    });
  };

  const removeAllExistingMedia = () => {
    setKeepExistingMedia([]);
  };

  const keepAllExistingMedia = () => {
    setKeepExistingMedia(existingMedia.map((_, index) => index));
  };

  const getMediaPreview = (file) => {
    const url = URL.createObjectURL(file);
    const isVideo = file.type.startsWith("video/");

    return (
      <div className="relative">
        {isVideo ? (
          <video
            src={url}
            className="w-20 h-20 object-cover rounded"
            controls={false}
          />
        ) : (
          <img
            src={url}
            alt="Preview"
            className="w-20 h-20 object-cover rounded"
          />
        )}
      </div>
    );
  };

  const getExistingMediaPreview = (media) => {
    const isVideo = media.type === "video";
    const mediaUrl = media.url.startsWith("http")
      ? media.url
      : `${process.env.REACT_APP_API_URL}${media.url}`;

    return (
      <div className="relative">
        {isVideo ? (
          <video
            src={mediaUrl}
            className="w-20 h-20 object-cover rounded"
            controls={false}
            onError={(e) => {
              console.error("Video load error:", e.target.src);
              e.target.style.display = "none";
              e.target.nextSibling.style.display = "block";
            }}
          />
        ) : (
          <img
            src={mediaUrl}
            alt="Existing media"
            className="w-20 h-20 object-cover rounded"
            onError={(e) => {
              console.error("Image load error:", e.target.src);
              e.target.src =
                "data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODAiIGhlaWdodD0iODAiIHZpZXdCb3g9IjAgMCA4MCA4MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjgwIiBoZWlnaHQ9IjgwIiBmaWxsPSIjRjNGNEY2Ii8+CjxwYXRoIGQ9Ik0yNCAzMkwyNCA0OEw1NiA0MEwyNCAzMloiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+";
            }}
          />
        )}
        <div
          className="absolute inset-0 flex items-center justify-center bg-red-100 text-red-600 text-xs"
          style={{ display: "none" }}
        >
          Failed to load
        </div>
      </div>
    );
  };

  const isTimeInPast = (timeString) => {
    if (!timeString) return false;
    const timeDate = new Date(timeString);
    const now = new Date();
    return timeDate < now;
  };

  const getTimeStatus = (timeString) => {
    if (!timeString) return null;

    if (isTimeInPast(timeString)) {
      // Check if this time was already sent
      const sentTimes = post?.sent_times || {};
      const wasSent = Object.keys(sentTimes).some((sentTime) => {
        return new Date(sentTime).getTime() === new Date(timeString).getTime();
      });

      return wasSent ? "sent" : "past";
    }

    return "future";
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);

    const formDataToSend = new FormData();

    // Add group IDs
    formData.group_ids.forEach((groupId, index) => {
      formDataToSend.append(`group_ids[${index}]`, groupId);
    });

    formDataToSend.append("text", formData.text);

    // Add schedule times (including past ones - backend will handle them)
    const validScheduleTimes = formData.schedule_times.filter((time) => time);
    validScheduleTimes.forEach((time, index) => {
      formDataToSend.append(`schedule_times[${index}]`, time);
    });

    // Add advertiser info
    formDataToSend.append("advertiser_username", formData.advertiser_username);
    formDataToSend.append("amount_paid", formData.amount_paid);
    formDataToSend.append("currency", formData.currency);

    // Add existing media to keep
    keepExistingMedia.forEach((index, i) => {
      formDataToSend.append(`keep_existing_media[${i}]`, index);
    });

    // Add new media files
    newMedia.forEach((file, index) => {
      formDataToSend.append(`media[${index}]`, file);
    });

    try {
      await axios.post(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/${postId}/update-with-media`,
        formDataToSend,
        {
          headers: {
            "Content-Type": "multipart/form-data",
          },
        }
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

  return (
    <div className="max-w-3xl mx-auto">
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("edit_post")}
        </h1>
        <p className="text-sm text-gray-500 mt-1">
          {t("editing_post_for")} {formData.group_ids.length} group(s)
        </p>
        <div className="mt-2 text-sm text-blue-600">
          <p>
            Posts are always editable. You can add, remove, or modify schedule
            times anytime.
          </p>
          <p>
            Past times will not send messages, future times will be queued
            automatically.
          </p>
        </div>
      </div>

      <form
        onSubmit={handleSubmit}
        className="space-y-6 bg-white shadow px-6 py-8 rounded-lg"
      >
        {/* Group Selection */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-3">
            {t("select_groups")} ({formData.group_ids.length} selected)
          </label>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-60 overflow-y-auto border border-gray-200 rounded-lg p-3">
            {groups.map((group) => {
              const groupId = group.id || group._id;
              const isSelected = formData.group_ids.includes(groupId);

              return (
                <div
                  key={groupId}
                  onClick={() => handleGroupSelection(groupId)}
                  className={`p-3 rounded-lg border cursor-pointer transition-colors ${
                    isSelected
                      ? "border-blue-500 bg-blue-50"
                      : "border-gray-200 hover:border-gray-300"
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {group.title}
                      </p>
                      <p className="text-xs text-gray-500">
                        {group.member_count} members
                      </p>
                    </div>
                    {isSelected && (
                      <CheckIcon className="h-5 w-5 text-blue-500 flex-shrink-0 ml-2" />
                    )}
                  </div>
                </div>
              );
            })}
          </div>

          {formData.group_ids.length === 0 && (
            <p className="mt-2 text-sm text-red-600">
              Please select at least one group.
            </p>
          )}
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
          <p className="text-sm text-gray-500 mb-3">
            You can add times in the past or future. Past times won't send,
            future times will be queued.
          </p>
          {formData.schedule_times.map((time, index) => {
            const timeStatus = getTimeStatus(time);
            return (
              <div key={index} className="flex items-center mb-2">
                <input
                  type="datetime-local"
                  value={time}
                  onChange={(e) =>
                    handleScheduleTimeChange(index, e.target.value)
                  }
                  required
                  className={`flex-1 px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${
                    timeStatus === "past"
                      ? "border-red-300 bg-red-50"
                      : timeStatus === "sent"
                      ? "border-green-300 bg-green-50"
                      : "border-gray-300"
                  }`}
                />
                <div className="ml-2 flex items-center">
                  {timeStatus === "past" && (
                    <span className="text-xs text-red-600 bg-red-100 px-2 py-1 rounded">
                      Past
                    </span>
                  )}
                  {timeStatus === "sent" && (
                    <span className="text-xs text-green-600 bg-green-100 px-2 py-1 rounded flex items-center">
                      <CheckIcon className="h-3 w-3 mr-1" />
                      Sent
                    </span>
                  )}
                  {timeStatus === "future" && (
                    <span className="text-xs text-blue-600 bg-blue-100 px-2 py-1 rounded flex items-center">
                      <ClockIcon className="h-3 w-3 mr-1" />
                      Queued
                    </span>
                  )}
                </div>
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
            );
          })}
          <button
            type="button"
            onClick={addScheduleTime}
            className="mt-2 inline-flex items-center px-3 py-1 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            <CalendarIcon className="mr-1 h-4 w-4" />
            {t("add_another_time")}
          </button>
        </div>

        {/* Existing Media */}
        {existingMedia.length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Existing Media Files
            </label>
            <div className="space-y-4">
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={keepAllExistingMedia}
                  className="text-sm text-green-600 hover:text-green-800"
                >
                  Keep All
                </button>
                <button
                  type="button"
                  onClick={removeAllExistingMedia}
                  className="text-sm text-red-600 hover:text-red-800"
                >
                  Remove All
                </button>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {existingMedia.map((media, index) => (
                  <div
                    key={index}
                    className={`relative border-2 rounded-lg p-2 ${
                      keepExistingMedia.includes(index)
                        ? "border-green-500 bg-green-50"
                        : "border-red-500 bg-red-50"
                    }`}
                  >
                    {getExistingMediaPreview(media)}
                    <div className="mt-2 flex justify-between items-center">
                      <span className="text-xs text-gray-600">
                        {media.type}
                      </span>
                      <button
                        type="button"
                        onClick={() => toggleKeepExistingMedia(index)}
                        className={`text-xs px-2 py-1 rounded ${
                          keepExistingMedia.includes(index)
                            ? "bg-green-600 text-white"
                            : "bg-red-600 text-white"
                        }`}
                      >
                        {keepExistingMedia.includes(index) ? "Keep" : "Remove"}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {/* New Media Upload */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Add New Media Files ({t("optional")})
          </label>
          <div className="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
            <div className="space-y-1 text-center">
              <PhotographIcon className="mx-auto h-12 w-12 text-gray-400" />
              <div className="flex text-sm text-gray-600">
                <label
                  htmlFor="new-media"
                  className="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500"
                >
                  <span>{t("upload_files")}</span>
                  <input
                    id="new-media"
                    name="new-media"
                    type="file"
                    multiple
                    accept="image/*,video/*"
                    onChange={handleNewMediaChange}
                    className="sr-only"
                  />
                </label>
                <p className="pl-1">{t("or_drag_and_drop")}</p>
              </div>
              <p className="text-xs text-gray-500">{t("file_size_limit")}</p>
            </div>
          </div>

          {/* New Media Preview */}
          {newMedia.length > 0 && (
            <div className="mt-4">
              <h4 className="text-sm font-medium text-gray-900 mb-2">
                New Files to Upload:
              </h4>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {newMedia.map((file, index) => (
                  <div
                    key={index}
                    className="relative border border-gray-300 rounded-lg p-2"
                  >
                    {getMediaPreview(file)}
                    <div className="mt-2 flex justify-between items-center">
                      <span className="text-xs text-gray-600 truncate">
                        {file.name}
                      </span>
                      <button
                        type="button"
                        onClick={() => removeNewMedia(index)}
                        className="text-red-600 hover:text-red-800"
                      >
                        <TrashIcon className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
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
            disabled={submitting || formData.group_ids.length === 0}
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
