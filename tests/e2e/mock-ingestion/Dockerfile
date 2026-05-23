FROM golang:1.22-alpine AS build
WORKDIR /src
COPY main.go ./
RUN go build -o /out/mock-ingestion main.go

FROM alpine:3.20
WORKDIR /app
COPY --from=build /out/mock-ingestion /app/mock-ingestion
EXPOSE 9999
ENTRYPOINT ["/app/mock-ingestion"]
