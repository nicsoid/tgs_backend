// src/pages/ScheduledPosts.js - Updated to show posts as always editable

import React, { useState, useEffect, useCallback } from "react";
import { Link } from "react-router-dom";
import axios from "axios";
import { format } from "date-fns";
import {
  CalendarIcon,
  ClockIcon,
  CurrencyDollarIcon,
  TrashIcon,
  PlusIcon,
  PencilIcon,
  UserGroupIcon,
  CheckIcon,
} from "@heroicons/react/outline";
import { useTranslation } from "react-i18next";

const ScheduledPosts = () => {
  const { t } = useTranslation();
  const [posts, setPosts] = useState([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const fetchPosts = useCallback(async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts?page=${page}`
      );
      setPosts(response.data.data);
      setTotalPages(response.data.last_page);
    } catch (error) {
      console.error("Failed to fetch posts:", error);
    }
  }, [page]);

  useEffect(() => {
    fetchPosts();
  }, [fetchPosts]);

  const deletePost = async (post) => {
    const postId = post.id || post._id;

    if (!postId) {
      console.error("No post ID found:", post);
      alert(t("no_post_id_error"));
      return;
    }

    if (!window.confirm(t("confirm_delete_post"))) {
      return;
    }

    try {
      await axios.delete(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/${postId}`
      );

      setPosts(posts.filter((p) => (p.id || p._id) !== postId));
      alert(t("post_deleted_successfully"));
    } catch (error) {
      console.error("Failed to delete post:", error);
      alert(
        t("failed_to_delete_post") + " " + (error.response?.data?.error || "")
      );
    }
  };

  const getPostStatus = (post) => {
    const now = new Date();
    const scheduleTimes = post.schedule_times || [];

    // Count future times
    const futureTimes = scheduleTimes.filter(
      (time) => new Date(time) > now
    ).length;

    // Count sent messages (from statistics if available)
    const statistics = post.statistics || {};
    const sentCount = statistics.total_sent || 0;
    const totalScheduled = scheduleTimes.length * (post.group_ids?.length || 1);

    if (futureTimes > 0) {
      return {
        label: "Active",
        color: "bg-green-100 text-green-800",
        description: `${futureTimes} future sends queued`,
      };
    } else if (sentCount === totalScheduled && totalScheduled > 0) {
      return {
        label: "Completed",
        color: "bg-blue-100 text-blue-800",
        description: "All messages sent",
      };
    } else if (sentCount > 0) {
      return {
        label: "Partial",
        color: "bg-yellow-100 text-yellow-800",
        description: `${sentCount}/${totalScheduled} sent`,
      };
    } else if (scheduleTimes.length > 0) {
      return {
        label: "No Future Sends",
        color: "bg-gray-100 text-gray-800",
        description: "All times are in the past",
      };
    } else {
      return {
        label: "Draft",
        color: "bg-gray-100 text-gray-800",
        description: "No schedule times set",
      };
    }
  };

  const renderGroupsList = (post) => {
    const groups = post.groups_data || post.groups || [];
    const groupIds = post.group_ids || (post.group_id ? [post.group_id] : []);

    if (groups.length === 0 && post.group) {
      return (
        <span className="text-sm font-medium text-indigo-600">
          {post.group.title}
        </span>
      );
    }

    if (groups.length === 1) {
      return (
        <span className="text-sm font-medium text-indigo-600">
          {groups[0].title}
        </span>
      );
    }

    if (groups.length > 1) {
      return (
        <div className="flex items-center">
          <UserGroupIcon className="h-4 w-4 text-indigo-500 mr-1" />
          <span className="text-sm font-medium text-indigo-600">
            {groups.length} groups:{" "}
            {groups
              .slice(0, 2)
              .map((g) => g.title)
              .join(", ")}
            {groups.length > 2 && ` +${groups.length - 2} more`}
          </span>
        </div>
      );
    }

    return (
      <span className="text-sm text-gray-500">
        {groupIds.length} group{groupIds.length !== 1 ? "s" : ""}
      </span>
    );
  };

  const getNextSendTime = (post) => {
    const now = new Date();
    const futureTimes = post.schedule_times
      ?.filter((time) => new Date(time) > now)
      ?.sort();

    return futureTimes && futureTimes.length > 0 ? futureTimes[0] : null;
  };

  const getPastSendCount = (post) => {
    const now = new Date();
    const pastTimes =
      post.schedule_times?.filter((time) => new Date(time) <= now) || [];

    return pastTimes.length;
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("scheduled_posts")}
        </h1>
        <Link
          to="/posts/create"
          className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          <PlusIcon className="-ml-1 mr-2 h-5 w-5" />
          {t("schedule_new_post")}
        </Link>
      </div>

      <div className="bg-white shadow overflow-hidden sm:rounded-md">
        {posts.length === 0 ? (
          <div className="text-center py-12">
            <CalendarIcon className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">
              {t("no_scheduled_posts")}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {t("get_started_by_scheduling")}
            </p>
            <div className="mt-6">
              <Link
                to="/posts/create"
                className="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              >
                <PlusIcon className="-ml-1 mr-2 h-5 w-5" />
                {t("schedule_new_post")}
              </Link>
            </div>
          </div>
        ) : (
          <ul className="divide-y divide-gray-200">
            {posts.map((post) => {
              const status = getPostStatus(post);
              const nextSend = getNextSendTime(post);
              const pastSendCount = getPastSendCount(post);

              return (
                <li key={post._id}>
                  <div className="px-4 py-4 sm:px-6">
                    <div className="flex items-center justify-between">
                      <div className="flex-1">
                        <div className="flex items-center justify-between">
                          {renderGroupsList(post)}
                          <div className="ml-2 flex-shrink-0 flex items-center space-x-2">
                            <span
                              className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${status.color}`}
                            >
                              {status.label}
                            </span>
                          </div>
                        </div>
                        <div className="mt-2 sm:flex sm:justify-between">
                          <div className="sm:flex sm:flex-col space-y-1">
                            <div className="flex items-center text-sm text-gray-500">
                              <ClockIcon className="flex-shrink-0 mr-1.5 h-4 w-4 text-gray-400" />
                              <span>
                                {post.schedule_times?.length || 0} scheduled
                                times
                                {pastSendCount > 0 &&
                                  ` (${pastSendCount} past)`}
                              </span>
                            </div>

                            <div className="flex items-center text-sm text-gray-500">
                              <CurrencyDollarIcon className="flex-shrink-0 mr-1.5 h-4 w-4 text-gray-400" />
                              <span>
                                {post.advertiser?.amount_paid || 0}{" "}
                                {post.advertiser?.currency || "USD"} {t("from")}{" "}
                                @
                                {post.advertiser?.telegram_username ||
                                  "unknown"}
                              </span>
                            </div>

                            {nextSend && (
                              <div className="flex items-center text-sm text-green-600">
                                <CalendarIcon className="flex-shrink-0 mr-1.5 h-4 w-4 text-green-400" />
                                <span>
                                  Next:{" "}
                                  {format(
                                    new Date(nextSend),
                                    "MMM d, yyyy HH:mm"
                                  )}
                                </span>
                              </div>
                            )}

                            {post.statistics?.total_sent > 0 && (
                              <div className="flex items-center text-sm text-blue-600">
                                <CheckIcon className="flex-shrink-0 mr-1.5 h-4 w-4 text-blue-400" />
                                <span>
                                  {post.statistics.total_sent} messages sent
                                </span>
                              </div>
                            )}
                          </div>

                          <div className="mt-2 flex items-center text-sm text-gray-500 sm:mt-0">
                            <p>
                              {t("created")}{" "}
                              {format(new Date(post.created_at), "MMM d, yyyy")}
                            </p>
                          </div>
                        </div>

                        <div className="mt-2">
                          <p className="text-sm text-gray-900 line-clamp-2">
                            {post.content?.text || "No message text"}
                          </p>
                        </div>

                        {post.content?.media &&
                          post.content.media.length > 0 && (
                            <div className="mt-2">
                              <p className="text-sm text-gray-500">
                                {post.content.media.length}{" "}
                                {t("media_files_attached")}
                              </p>
                            </div>
                          )}

                        <div className="mt-2 text-xs text-gray-400">
                          {status.description}
                        </div>
                      </div>

                      {/* Always show edit and delete buttons */}
                      <div className="ml-4 flex items-center space-x-2">
                        <Link
                          to={`/posts/edit/${post.id || post._id}`}
                          className="inline-flex items-center p-2 border border-transparent rounded-full shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                          title="Edit post (always available)"
                        >
                          <PencilIcon className="h-5 w-5" />
                        </Link>
                        <button
                          onClick={() => deletePost(post)}
                          className="inline-flex items-center p-2 border border-transparent rounded-full shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                          title="Delete post"
                        >
                          <TrashIcon className="h-5 w-5" />
                        </button>
                      </div>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
          <div className="flex-1 flex justify-between sm:hidden">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              {t("previous")}
            </button>
            <button
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={page === totalPages}
              className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              {t("next")}
            </button>
          </div>
          <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
              <p className="text-sm text-gray-700">
                {t("showing")}{" "}
                <span className="font-medium">{(page - 1) * 20 + 1}</span>{" "}
                {t("to")}{" "}
                <span className="font-medium">
                  {Math.min(page * 20, posts.length)}
                </span>{" "}
                {t("results")}
              </p>
            </div>
            <div>
              <nav
                className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px"
                aria-label="Pagination"
              >
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                >
                  {t("previous")}
                </button>
                <span className="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                  {page} / {totalPages}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={page === totalPages}
                  className="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                >
                  {t("next")}
                </button>
              </nav>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ScheduledPosts;
