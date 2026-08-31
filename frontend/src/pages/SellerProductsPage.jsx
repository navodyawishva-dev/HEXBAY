import { useEffect, useMemo, useState } from "react";
import { apiRequest, apiUpload, mediaUrl } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import SellerNav from "../components/SellerNav";
import StatusBadge from "../components/StatusBadge";
import Modal from "../components/Modal";

const emptyListing = {
  category_id: "",
  brand_name: "",
  product_name: "",
  model: "",
  sku: "",
  condition_type: "new",
  price: "",
  vendor_description: "",
  warranty_summary: "",
  initial_stock: 0,
  specifications: {},
};

export default function SellerProductsPage({ navigate, path }) {
  const { token } = useAuth();
  const [catalogue, setCatalogue] = useState({ categories: [], brands: [] });
  const [listings, setListings] = useState([]);
  const [editor, setEditor] = useState(null);
  const [message, setMessage] = useState("");
  const [imageFile, setImageFile] = useState(null);
  const [imageAlt, setImageAlt] = useState("");
  const [uploadingImage, setUploadingImage] = useState(false);

  const load = () =>
    Promise.all([
      apiRequest("/seller/catalogue-options", { token }),
      apiRequest("/seller/listings", { token }),
    ]).then(([catalogueResponse, listingResponse]) => {
      setCatalogue(catalogueResponse.data);
      setListings(listingResponse.data.listings);
    });

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token]);

  const selectedCategory = useMemo(
    () =>
      catalogue.categories.find(
        (category) => Number(category.id) === Number(editor?.category_id),
      ),
    [catalogue.categories, editor?.category_id],
  );

  const editListing = async (listingId) => {
    setMessage("");
    try {
      const response = await apiRequest(`/seller/listings/${listingId}`, { token });
      setEditor({
        ...emptyListing,
        ...response.data.listing,
        initial_stock: response.data.listing.quantity_on_hand,
        identityLocked: true,
      });
    } catch (error) {
      setMessage(error.message);
    }
  };

  const saveListing = async () => {
    setMessage("");
    try {
      const pathName = editor.id
        ? `/seller/listings/${editor.id}`
        : "/seller/listings";
      const response = await apiRequest(pathName, {
        method: "POST",
        token,
        body: editor,
      });
      setEditor({
        ...emptyListing,
        ...response.data.listing,
        initial_stock: response.data.listing.quantity_on_hand,
        identityLocked: true,
      });
      setMessage(
        response.data.listing.status === "pending_approval"
          ? "Listing saved and sent for administrator approval. You can add product images now."
          : "Listing saved.",
      );
      await load();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const setSpecification = (code, value) =>
    setEditor({
      ...editor,
      specifications: { ...editor.specifications, [code]: value },
    });

  const reloadEditor = async () => {
    const response = await apiRequest(`/seller/listings/${editor.id}`, { token });
    setEditor({
      ...emptyListing,
      ...response.data.listing,
      initial_stock: response.data.listing.quantity_on_hand,
      identityLocked: true,
    });
    await load();
  };

  const uploadImage = async () => {
    if (!editor?.id || !imageFile) return;
    setUploadingImage(true);
    setMessage("");
    try {
      const formData = new FormData();
      formData.append("file", imageFile);
      formData.append("alt_text", imageAlt);
      await apiUpload(`/seller/listings/${editor.id}/images`, {
        formData,
        token,
      });
      setImageFile(null);
      setImageAlt("");
      await reloadEditor();
      setMessage("Product image uploaded securely.");
    } catch (error) {
      setMessage(error.message);
    } finally {
      setUploadingImage(false);
    }
  };

  const deleteImage = async (imageId) => {
    setMessage("");
    try {
      await apiRequest(
        `/seller/listings/${editor.id}/images/${imageId}/delete`,
        { method: "POST", token },
      );
      await reloadEditor();
      setMessage("Product image removed.");
    } catch (error) {
      setMessage(error.message);
    }
  };

  return (
    <section className="content-section page-section">
      <SellerNav path={path} navigate={navigate} />
      <div className="account-hero">
        <div>
          <span className="section-kicker">Structured catalogue</span>
          <h1 className="page-title">Products</h1>
          <p>Add consistent product information for search and compatibility.</p>
        </div>
        <button
          className="button button-primary"
          onClick={() => setEditor({ ...emptyListing })}
        >
          Add product
        </button>
      </div>
      {message && <div className="alert alert-info">{message}</div>}

      <div className="seller-product-grid">
        {listings.length === 0 ? (
          <div className="admin-panel compact-empty">
            No products yet. Create your first structured listing.
          </div>
        ) : (
          listings.map((listing) => (
            <article className="seller-product-card" key={listing.id}>
              <div className="seller-product-card-top">
                <span>{listing.category_name}</span>
                <StatusBadge status={listing.status} />
              </div>
              {listing.images?.[0] ? (
                <img
                  className="seller-product-image"
                  src={mediaUrl(
                    "product-images",
                    listing.images[0].stored_filename,
                  )}
                  alt={listing.images[0].alt_text || listing.product_name}
                />
              ) : (
                <div className="product-placeholder">
                  {listing.brand_name.slice(0, 1)}
                </div>
              )}
              <h2>{listing.product_name}</h2>
              <p>
                {listing.brand_name} · {listing.model}
              </p>
              <dl>
                <div>
                  <dt>SKU</dt>
                  <dd>{listing.sku}</dd>
                </div>
                <div>
                  <dt>Price</dt>
                  <dd>LKR {Number(listing.price).toLocaleString()}</dd>
                </div>
                <div>
                  <dt>Stock</dt>
                  <dd>{listing.quantity_on_hand}</dd>
                </div>
              </dl>
              {listing.status_reason && (
                <div className="decision-reason">
                  <strong>Review note</strong>
                  <p>{listing.status_reason}</p>
                </div>
              )}
              <button
                className="button button-ghost full-button"
                onClick={() => editListing(listing.id)}
              >
                Edit listing
              </button>
            </article>
          ))
        )}
      </div>

      {editor && (
        <Modal
          onClose={() => setEditor(null)}
          className="seller-product-modal"
          ariaLabel="Seller product form"
        >
            <span className="section-kicker">Seller product form</span>
            <h2>{editor.id ? "Edit listing" : "Create product listing"}</h2>
            <div className="form-grid">
              <label>
                Category
                <select
                  value={editor.category_id}
                  disabled={editor.identityLocked}
                  onChange={(event) =>
                    setEditor({
                      ...editor,
                      category_id: event.target.value,
                      specifications: {},
                    })
                  }
                >
                  <option value="">Choose category</option>
                  {catalogue.categories.map((category) => (
                    <option value={category.id} key={category.id}>
                      {category.name}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Brand
                <input
                  list="seller-brand-list"
                  disabled={editor.identityLocked}
                  value={editor.brand_name}
                  onChange={(event) =>
                    setEditor({ ...editor, brand_name: event.target.value })
                  }
                />
                <datalist id="seller-brand-list">
                  {catalogue.brands.map((brand) => (
                    <option value={brand.name} key={brand.id} />
                  ))}
                </datalist>
              </label>
              <label>
                Product name
                <input
                  disabled={editor.identityLocked}
                  value={editor.product_name}
                  onChange={(event) =>
                    setEditor({ ...editor, product_name: event.target.value })
                  }
                />
              </label>
              <label>
                Model
                <input
                  disabled={editor.identityLocked}
                  value={editor.model}
                  onChange={(event) =>
                    setEditor({ ...editor, model: event.target.value })
                  }
                />
              </label>
              <label>
                Shop SKU
                <input
                  value={editor.sku}
                  onChange={(event) =>
                    setEditor({ ...editor, sku: event.target.value })
                  }
                />
              </label>
              <label>
                Condition
                <select
                  value={editor.condition_type}
                  onChange={(event) =>
                    setEditor({ ...editor, condition_type: event.target.value })
                  }
                >
                  <option value="new">New</option>
                  <option value="used">Used</option>
                  <option value="refurbished">Refurbished</option>
                </select>
              </label>
              <label>
                Price (LKR)
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={editor.price}
                  onChange={(event) =>
                    setEditor({ ...editor, price: event.target.value })
                  }
                />
              </label>
              <label>
                {editor.id ? "Current stock" : "Initial stock"}
                <input
                  type="number"
                  min="0"
                  disabled={Boolean(editor.id)}
                  value={editor.initial_stock}
                  onChange={(event) =>
                    setEditor({
                      ...editor,
                      initial_stock: Number(event.target.value),
                    })
                  }
                />
              </label>
              <label className="full-width">
                Seller description
                <textarea
                  rows="4"
                  value={editor.vendor_description ?? ""}
                  onChange={(event) =>
                    setEditor({
                      ...editor,
                      vendor_description: event.target.value,
                    })
                  }
                />
              </label>
              <label className="full-width">
                Warranty summary
                <input
                  value={editor.warranty_summary ?? ""}
                  onChange={(event) =>
                    setEditor({
                      ...editor,
                      warranty_summary: event.target.value,
                    })
                  }
                />
              </label>
            </div>

            {selectedCategory && (
              <div className="dynamic-specifications">
                <div>
                  <h3>{selectedCategory.name} specifications</h3>
                  <p>Controlled values keep comparison and compatibility reliable.</p>
                </div>
                <div className="form-grid">
                  {selectedCategory.specifications.map((specification) => {
                    const value =
                      editor.specifications[specification.code] ??
                      (specification.data_type === "multi_option" ? [] : "");
                    if (specification.data_type === "boolean") {
                      return (
                        <label className="check-row" key={specification.id}>
                          <input
                            type="checkbox"
                            checked={Boolean(value)}
                            onChange={(event) =>
                              setSpecification(
                                specification.code,
                                event.target.checked,
                              )
                            }
                          />
                          {specification.display_name}
                        </label>
                      );
                    }
                    if (specification.data_type === "option") {
                      return (
                        <label key={specification.id}>
                          {specification.display_name}
                          <select
                            value={value}
                            onChange={(event) =>
                              setSpecification(
                                specification.code,
                                event.target.value,
                              )
                            }
                          >
                            <option value="">Choose value</option>
                            {specification.options.map((option) => (
                              <option value={option.value_code} key={option.id}>
                                {option.display_value}
                              </option>
                            ))}
                          </select>
                        </label>
                      );
                    }
                    if (specification.data_type === "multi_option") {
                      return (
                        <fieldset className="spec-option-fieldset" key={specification.id}>
                          <legend>{specification.display_name}</legend>
                          {specification.options.map((option) => (
                            <label className="check-row" key={option.id}>
                              <input
                                type="checkbox"
                                checked={value.includes(option.value_code)}
                                onChange={(event) => {
                                  const next = event.target.checked
                                    ? [...value, option.value_code]
                                    : value.filter(
                                        (item) => item !== option.value_code,
                                      );
                                  setSpecification(specification.code, next);
                                }}
                              />
                              {option.display_value}
                            </label>
                          ))}
                        </fieldset>
                      );
                    }
                    return (
                      <label key={specification.id}>
                        {specification.display_name}
                        {specification.unit ? ` (${specification.unit})` : ""}
                        <input
                          type={
                            ["integer", "decimal"].includes(specification.data_type)
                              ? "number"
                              : "text"
                          }
                          step={
                            specification.data_type === "decimal" ? "0.01" : undefined
                          }
                          value={value}
                          onChange={(event) =>
                            setSpecification(
                              specification.code,
                              event.target.value,
                            )
                          }
                        />
                      </label>
                    );
                  })}
                </div>
              </div>
            )}

            {editor.id && (
              <section className="product-image-manager">
                <div>
                  <h3>Product images</h3>
                  <p>Add up to six PNG, JPG or WebP images, maximum 6 MB each.</p>
                </div>
                <div className="product-image-strip">
                  {(editor.images ?? []).map((image) => (
                    <figure key={image.id}>
                      <img
                        src={mediaUrl("product-images", image.stored_filename)}
                        alt={image.alt_text || editor.product_name}
                      />
                      <button
                        type="button"
                        className="image-remove-button"
                        onClick={() => deleteImage(image.id)}
                      >
                        Remove
                      </button>
                    </figure>
                  ))}
                  {(editor.images ?? []).length === 0 && (
                    <div className="compact-empty">No product images yet.</div>
                  )}
                </div>
                <div className="image-upload-grid">
                  <label>
                    Image file
                    <input
                      type="file"
                      accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                      onChange={(event) =>
                        setImageFile(event.target.files?.[0] ?? null)
                      }
                    />
                  </label>
                  <label>
                    Accessible description
                    <input
                      value={imageAlt}
                      maxLength="190"
                      placeholder={editor.product_name}
                      onChange={(event) => setImageAlt(event.target.value)}
                    />
                  </label>
                  <button
                    type="button"
                    className="button button-ghost"
                    disabled={
                      !imageFile ||
                      uploadingImage ||
                      (editor.images ?? []).length >= 6
                    }
                    onClick={uploadImage}
                  >
                    {uploadingImage ? "Uploading…" : "Add image"}
                  </button>
                </div>
              </section>
            )}

            <div className="modal-actions">
              <button className="button button-ghost" onClick={() => setEditor(null)}>
                {editor.id ? "Done" : "Cancel"}
              </button>
              <button className="button button-primary" onClick={saveListing}>
                {editor.id ? "Save changes" : "Submit listing"}
              </button>
            </div>
        </Modal>
      )}
    </section>
  );
}
